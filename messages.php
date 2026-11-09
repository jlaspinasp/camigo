<?php
session_start();
require("db.php");
require("auth.php");

$me = require_user_row($conn);

// is realtime (Pusher) set up? If not, everything still works with a slow refresh.
$rt = function_exists('pusher_enabled') && pusher_enabled();

// ---- who am I talking to? (?u=username) ----
$want    = trim($_GET['u'] ?? $_POST['u'] ?? '');
$partner = null;
if ($want !== '') {
    $p = get_user_row($conn, $want);
    if ($p && (int)$p['id'] !== (int)$me['id']) $partner = $p;
}

function json_out($a) {
    header('Content-Type: application/json');
    echo json_encode($a);
    exit();
}

function safe_photo($row) {
    return (!empty($row['photo']) && preg_match('/^[a-f0-9]{24}\.jpg$/', $row['photo'])) ? $row['photo'] : '';
}

// a private profile can't be messaged first, but an existing conversation can continue
function can_message($conn, $meId, $p) {
    if (!empty($p['profile_visibility']) && $p['profile_visibility'] !== 'public') {
        $r = db_run($conn,
            "SELECT id FROM messages WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?) LIMIT 1",
            "iiii", array($meId, $p['id'], $p['id'], $meId));
        return (bool)$r['rows'];
    }
    return true;
}

$isAjax = !empty($_POST['ajax']);

// ================= MARK AS READ (called by the chat page) =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['read'])) {
    if (!csrf_ok() || !$partner) json_out(array('ok' => false));

    $r = db_run($conn, "UPDATE messages SET is_read = 1 WHERE recipient_id = ? AND sender_id = ? AND is_read = 0", "ii", array($me['id'], $partner['id']));
    if ($rt && $r['ok'] && $r['affected'] > 0) {
        pusher_trigger(array(user_channel($partner['id'])), 'seen', array('by' => (int)$me['id']));
    }
    json_out(array('ok' => true, 'unread' => unread_messages($conn, $me['id'])));
}

// ================= SEND =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {

    $body = trim(str_replace("\r\n", "\n", $_POST['body'] ?? ''));
    $body = preg_replace("/\n{3,}/", "\n\n", $body);
    $err  = '';
    $mid  = 0;
    $ts   = time();

    if (!csrf_ok()) {
        $err = "Your session expired. Please reload the page.";
    } elseif (!$partner) {
        $err = "We couldn't find that student.";
    } elseif (!can_message($conn, $me['id'], $partner)) {
        $err = "This student isn't taking new messages.";
    } elseif ($body === '') {
        $err = "Write a message first.";
    } elseif (str_len($body) > 1000) {
        $err = "Messages can be up to 1,000 characters.";
    } else {
        $rl = db_run($conn, "SELECT COUNT(*) AS n FROM messages WHERE sender_id = ? AND created_at > (NOW() - INTERVAL 1 MINUTE)", "i", array($me['id']));
        if ($rl['ok'] && $rl['rows'] && (int)$rl['rows'][0]['n'] >= 15) {
            $err = "You're sending messages too fast. Wait a moment and try again.";
        } else {
            $r = db_run($conn, "INSERT INTO messages (sender_id, recipient_id, body) VALUES (?, ?, ?)", "iis", array($me['id'], $partner['id'], $body));
            if (!$r['ok']) {
                $err = "We couldn't send that. Please try again.";
            } else {
                $mid = (int)mysqli_insert_id($conn);
                if ($rt) {
                    // push it to the other person right away, on whatever page they're on
                    pusher_trigger(array(user_channel($partner['id'])), 'new-message', array(
                        'id'       => $mid,
                        'from'     => (int)$me['id'],
                        'body'     => $body,
                        'ts'       => $ts,
                        'name'     => full_name($me),
                        'username' => $me['username'],
                        'photo'    => safe_photo($me),
                        'initial'  => first_letter($me['first_name']),
                    ));
                }
            }
        }
    }

    if ($isAjax) {
        if ($err !== '') json_out(array('ok' => false, 'error' => $err));
        json_out(array('ok' => true, 'message' => array('id' => $mid, 'ts' => $ts)));
    }

    if ($err !== '') flash_set($err, "error");
    header("Location: messages" . ($partner ? "?u=" . urlencode($partner['username']) : ""));
    exit();
}

// ---- opening a conversation marks it as read (and tells them "Seen") ----
if ($partner) {
    $r = db_run($conn, "UPDATE messages SET is_read = 1 WHERE recipient_id = ? AND sender_id = ? AND is_read = 0", "ii", array($me['id'], $partner['id']));
    if ($rt && $r['ok'] && $r['affected'] > 0) {
        pusher_trigger(array(user_channel($partner['id'])), 'seen', array('by' => (int)$me['id']));
    }
}

// ---- conversation list: latest message with each person ----
$convos = db_run($conn,
    "SELECT u.id, u.username, u.first_name, u.last_name, u.photo,
            m.body, m.sender_id, UNIX_TIMESTAMP(m.created_at) AS ts,
            (SELECT COUNT(*) FROM messages x WHERE x.sender_id = u.id AND x.recipient_id = ? AND x.is_read = 0) AS unread
     FROM messages m
     JOIN users u ON u.id = IF(m.sender_id = ?, m.recipient_id, m.sender_id)
     WHERE m.id IN (
         SELECT MAX(id) FROM messages WHERE sender_id = ? OR recipient_id = ?
         GROUP BY IF(sender_id = ?, recipient_id, sender_id)
     )
     ORDER BY m.id DESC LIMIT 50",
    "iiiii", array($me['id'], $me['id'], $me['id'], $me['id'], $me['id']));

$setupMissing = !$convos['ok'];
$list = $convos['rows'];

// ---- the open conversation ----
$thread = array();
$allowed = false;
$seenInit = false;
if ($partner && !$setupMissing) {
    $allowed = can_message($conn, $me['id'], $partner);
    $tr = db_run($conn,
        "SELECT id, sender_id, body, is_read, UNIX_TIMESTAMP(created_at) AS ts FROM messages
         WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)
         ORDER BY id DESC LIMIT 100",
        "iiii", array($me['id'], $partner['id'], $partner['id'], $me['id']));
    $thread = array_reverse($tr['rows']);
    if ($thread) {
        $lastM = $thread[count($thread) - 1];
        $seenInit = ((int)$lastM['sender_id'] === (int)$me['id'] && (int)$lastM['is_read'] === 1);
    }
}

$flash = flash_get();
$message = $flash ? $flash[0] : "";
$message_class = $flash ? $flash[1] : "";

function day_label($ts) {
    $d = date('Y-m-d', $ts);
    if ($d === date('Y-m-d')) return 'Today';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    return date('M j, Y', $ts);
}
function one_line($s, $n) {
    return str_cut(trim(preg_replace('/\s+/', ' ', (string)$s)), $n);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $partner ? e(full_name($partner)) . ' | ' : ''; ?>Messages | Camigo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include("topbar.php"); ?>

<main class="main">

    <?php if ($message != "") { ?>
        <div class="msg <?php echo e($message_class); ?>"><?php echo e($message); ?></div>
    <?php } ?>

    <?php if ($setupMissing) { ?>

        <div class="msg error">Messaging isn't set up yet. Run <strong>messages.sql</strong> in phpMyAdmin.</div>

    <?php } else { ?>

    <div class="chat <?php echo $want !== '' ? 'has-thread' : ''; ?>">

        <!-- ---------- conversations ---------- -->
        <aside class="chat-list">
            <div class="chat-list-head">
                <h1>Messages</h1>
                <a href="students" class="btn-sm btn-ghost">New message</a>
            </div>

            <div class="chat-items">
                <?php if (!$list) { ?>
                    <p class="empty chat-none">No conversations yet. Find someone in the <a href="students">student directory</a> and say hi.</p>
                <?php } else { foreach ($list as $c) {
                    $isActive = $partner && (int)$partner['id'] === (int)$c['id'];
                    $un = (int)$c['unread'];
                ?>
                    <a href="messages?u=<?php echo e(urlencode($c['username'])); ?>" data-uid="<?php echo (int)$c['id']; ?>" class="convo <?php echo $isActive ? 'active' : ''; ?> <?php echo $un > 0 ? 'unread' : ''; ?>">
                        <div class="dir-avatar"><?php echo avatar_inner($c); ?></div>
                        <div class="convo-main">
                            <div class="convo-top">
                                <strong><?php echo e(full_name($c)); ?></strong>
                                <span class="convo-time"><?php echo e(time_ago($c['ts'])); ?></span>
                            </div>
                            <div class="convo-prev"><?php echo ((int)$c['sender_id'] === (int)$me['id']) ? 'You: ' : ''; ?><?php echo e(one_line($c['body'], 70)); ?></div>
                        </div>
                        <?php if ($un > 0) { ?><span class="convo-badge"><?php echo $un > 9 ? '9+' : $un; ?></span><?php } ?>
                    </a>
                <?php } } ?>
            </div>
        </aside>

        <!-- ---------- open conversation ---------- -->
        <section class="chat-pane">

            <?php if ($want === '') { ?>

                <div class="chat-empty">
                    <div class="lock"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4.5h14a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5h-8L6.5 20.5V16.5H5A1.5 1.5 0 013.5 15V6A1.5 1.5 0 015 4.5z"/></svg></div>
                    <h2>Your messages</h2>
                    <p class="empty">Pick a conversation, or start a new one from the <a href="students">student directory</a>.</p>
                </div>

            <?php } elseif (!$partner) { ?>

                <div class="chat-empty">
                    <h2>Student not found</h2>
                    <p class="empty">We couldn't find anyone with that username. <a href="students">Browse students</a></p>
                </div>

            <?php } else { ?>

                <div class="pane-head">
                    <a href="messages" class="pane-back" aria-label="Back to conversations">&larr;</a>
                    <div class="dir-avatar"><?php echo avatar_inner($partner); ?></div>
                    <div class="pane-who">
                        <strong><?php echo e(full_name($partner)); ?></strong>
                        <small>@<?php echo e($partner['username']); ?></small>
                    </div>
                    <a href="profile?u=<?php echo e(urlencode($partner['username'])); ?>" class="btn-sm btn-ghost">View profile</a>
                </div>

                <div class="thread" id="thread">
                    <?php if (!$thread) { ?>
                        <p class="thread-empty">This is the start of your conversation with <?php echo e($partner['first_name']); ?>. Say hello.</p>
                    <?php } else {
                        $lastDay = ''; $lastSender = 0;
                        foreach ($thread as $m) {
                            $day = date('Y-m-d', $m['ts']);
                            if ($day !== $lastDay) { echo '<div class="day">' . e(day_label($m['ts'])) . '</div>'; $lastDay = $day; $lastSender = 0; }
                            $mine = ((int)$m['sender_id'] === (int)$me['id']);
                            $first = ((int)$m['sender_id'] !== $lastSender);
                            $lastSender = (int)$m['sender_id'];
                    ?>
                        <div class="bubble <?php echo $mine ? 'me' : 'them'; ?> <?php echo $first ? 'first' : ''; ?>" data-id="<?php echo (int)$m['id']; ?>" data-ts="<?php echo (int)$m['ts']; ?>">
                            <?php echo nl2br(e($m['body'])); ?>
                            <time data-ts="<?php echo (int)$m['ts']; ?>"><?php echo e(date('g:i A', $m['ts'])); ?></time>
                        </div>
                    <?php } } ?>
                    <div class="typing" id="typing" hidden aria-label="<?php echo e($partner['first_name']); ?> is typing"><span></span><span></span><span></span></div>
                    <div class="seen" id="seen" <?php echo $seenInit ? '' : 'hidden'; ?>>Seen</div>
                </div>

                <?php if ($allowed) { ?>
                    <div class="composer-error" id="send-error" hidden></div>
                    <form method="POST" class="composer" id="composer">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="send" value="1">
                        <input type="hidden" name="u" value="<?php echo e($partner['username']); ?>">
                        <textarea name="body" id="msg-body" rows="1" maxlength="1000" placeholder="Write a message" aria-label="Message" required></textarea>
                        <button type="submit" class="btn-sm btn-dark">Send</button>
                    </form>
                <?php } else { ?>
                    <div class="composer-note">This student isn't taking new messages.</div>
                <?php } ?>

            <?php } ?>

        </section>
    </div>

    <?php } ?>

</main>

<?php if ($partner && !$setupMissing) { ?>
<script>
window.CHAT = <?php echo json_encode(array(
    'me'              => (int)$me['id'],
    'partner'         => (int)$partner['id'],
    'partnerName'     => full_name($partner),
    'partnerUsername' => $partner['username'],
    'partnerPhoto'    => safe_photo($partner),
    'partnerInitial'  => first_letter($partner['first_name']),
    'channel'         => (function_exists('chat_channel') ? chat_channel($me['id'], $partner['id']) : ''),
    'csrf'            => csrf_token(),
    'seen'            => $seenInit,
), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<?php } ?>

<script>
(function () {
    var C = window.CHAT || null;
    var RT = window.CamigoRT || null;
    var thread = document.getElementById("thread");
    var ta = document.getElementById("msg-body");
    var form = document.getElementById("composer");
    var items = document.querySelector(".chat-items");
    var typingEl = document.getElementById("typing");
    var seenEl = document.getElementById("seen");
    var errEl = document.getElementById("send-error");

    if (RT && C) RT.openPartner = C.partner;

    // ---------- small helpers ----------
    function pad(n) { return n < 10 ? "0" + n : "" + n; }
    function fmtTime(ts) {
        var d = new Date(ts * 1000), h = d.getHours(), ap = h >= 12 ? "PM" : "AM";
        h = h % 12 || 12;
        return h + ":" + pad(d.getMinutes()) + " " + ap;
    }
    function dayKey(ts) { var d = new Date(ts * 1000); return d.getFullYear() + "-" + d.getMonth() + "-" + d.getDate(); }
    function dayLabel(ts) {
        var d = new Date(ts * 1000), now = new Date(), y = new Date(); y.setDate(now.getDate() - 1);
        if (dayKey(ts) === dayKey(now / 1000)) return "Today";
        if (dayKey(ts) === dayKey(y / 1000)) return "Yesterday";
        return d.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
    }
    function oneLine(s, n) { return String(s).replace(/\s+/g, " ").trim().slice(0, n); }
    function nearBottom() { return !thread || thread.scrollHeight - thread.scrollTop - thread.clientHeight < 160; }
    function toBottom() { if (thread) thread.scrollTop = thread.scrollHeight; }

    // show every time in the viewer's own timezone
    document.querySelectorAll("time[data-ts]").forEach(function (t) { t.textContent = fmtTime(+t.dataset.ts); });
    toBottom();

    // ---------- the chat thread ----------
    function lastBubble() {
        var b = thread ? thread.querySelectorAll(".bubble") : [];
        return b.length ? b[b.length - 1] : null;
    }

    function setSeen(on) {
        if (!seenEl) return;
        var last = lastBubble();
        seenEl.hidden = !(on && last && last.classList.contains("me"));
    }

    function addBubble(m, mine, pending) {
        if (m.id && thread.querySelector('.bubble[data-id="' + m.id + '"]')) return null;

        var empty = thread.querySelector(".thread-empty");
        if (empty) empty.remove();

        var last = lastBubble(), first = !last || last.classList.contains("me") !== mine;
        if (!last || dayKey(+last.dataset.ts) !== dayKey(m.ts)) {
            var day = document.createElement("div");
            day.className = "day";
            day.textContent = dayLabel(m.ts);
            thread.insertBefore(day, typingEl);
            first = true;
        }

        var el = document.createElement("div");
        el.className = "bubble " + (mine ? "me" : "them") + (first ? " first" : "") + (pending ? " pending" : "");
        el.dataset.id = m.id || "";
        el.dataset.ts = m.ts;
        String(m.body).split("\n").forEach(function (line, i) {
            if (i) el.appendChild(document.createElement("br"));
            el.appendChild(document.createTextNode(line));
        });
        var t = document.createElement("time");
        t.dataset.ts = m.ts;
        t.textContent = fmtTime(m.ts);
        el.appendChild(t);
        thread.insertBefore(el, typingEl);
        return el;
    }

    var typingTimer = null;
    function hideTyping() { if (typingEl) typingEl.hidden = true; clearTimeout(typingTimer); }
    function showTyping() {
        if (!typingEl) return;
        var stick = nearBottom();
        typingEl.hidden = false;
        if (stick) toBottom();
        clearTimeout(typingTimer);
        typingTimer = setTimeout(hideTyping, 4500);
    }

    // ---------- the conversation list on the left ----------
    function touchConvo(uid, info, text, mine, bump) {
        if (!items) return;
        var none = items.querySelector(".chat-none");
        if (none) none.remove();

        var el = items.querySelector('.convo[data-uid="' + uid + '"]');
        if (!el) {
            el = document.createElement("a");
            el.className = "convo";
            el.dataset.uid = uid;
            el.href = "messages?u=" + encodeURIComponent(info.username);
            var av = document.createElement("div");
            av.className = "dir-avatar";
            if (info.photo) { var img = document.createElement("img"); img.src = "uploads/avatars/" + info.photo; img.alt = ""; av.appendChild(img); }
            else { av.textContent = info.initial; }
            var main = document.createElement("div"); main.className = "convo-main";
            var top = document.createElement("div"); top.className = "convo-top";
            var nm = document.createElement("strong"); nm.textContent = info.name;
            var tm = document.createElement("span"); tm.className = "convo-time";
            top.appendChild(nm); top.appendChild(tm);
            var pv = document.createElement("div"); pv.className = "convo-prev";
            main.appendChild(top); main.appendChild(pv);
            el.appendChild(av); el.appendChild(main);
            if (C && uid === C.partner) el.classList.add("active");
        }
        el.querySelector(".convo-prev").textContent = (mine ? "You: " : "") + oneLine(text, 70);
        el.querySelector(".convo-time").textContent = "Just now";

        if (bump) {
            var badge = el.querySelector(".convo-badge");
            if (!badge) { badge = document.createElement("span"); badge.className = "convo-badge"; badge.dataset.n = "0"; el.appendChild(badge); }
            var n = (+badge.dataset.n || (badge.textContent === "9+" ? 9 : +badge.textContent) || 0) + 1;
            badge.dataset.n = n;
            badge.textContent = n > 9 ? "9+" : n;
            el.classList.add("unread");
        }
        items.insertBefore(el, items.firstChild);
    }

    function clearConvoBadge(uid) {
        if (!items) return;
        var el = items.querySelector('.convo[data-uid="' + uid + '"]');
        if (!el) return;
        var b = el.querySelector(".convo-badge");
        if (b) b.remove();
        el.classList.remove("unread");
    }

    // ---------- telling the server I've read it ----------
    var pendingRead = false;
    function markRead() {
        if (!C || !form) return;
        if (document.hidden) { pendingRead = true; return; }
        pendingRead = false;
        var fd = new FormData();
        fd.append("read", "1"); fd.append("ajax", "1");
        fd.append("csrf", C.csrf); fd.append("u", C.partnerUsername);
        fetch(window.location.href, { method: "POST", body: fd, credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.ok) { if (RT) RT.setBadge(j.unread); clearConvoBadge(C.partner); }
            }).catch(function () {});
    }
    document.addEventListener("visibilitychange", function () { if (!document.hidden && pendingRead) markRead(); });

    // ---------- live events ----------
    if (RT) {
        RT.userChannel.bind("new-message", function (d) {
            var open = !!(C && d.from === C.partner);
            var info = { username: d.username, name: d.name, photo: d.photo, initial: d.initial };

            if (open) {
                hideTyping();
                var stick = nearBottom();
                addBubble({ id: d.id, ts: d.ts, body: d.body }, false, false);
                setSeen(false);
                if (stick) toBottom();
                markRead();
                touchConvo(d.from, info, d.body, false, document.hidden);
            } else {
                touchConvo(d.from, info, d.body, false, true);
            }
        });

        RT.userChannel.bind("seen", function (d) {
            if (C && d.by === C.partner) { C.seen = true; setSeen(true); }
        });

        // reconnecting after a drop: reload once so nothing is missed
        var wasDown = false;
        RT.pusher.connection.bind("state_change", function (s) {
            if (s.current === "unavailable" || s.current === "failed" || s.current === "disconnected") wasDown = true;
            if (s.current === "connected" && wasDown) {
                wasDown = false;
                if (!ta || ta.value.trim() === "") location.reload();
            }
        });
    }

    // ---------- typing indicator ----------
    var chat = null, lastTyping = 0;
    if (RT && C) {
        chat = RT.pusher.subscribe(C.channel);
        chat.bind("client-typing", function (d) { if (d && d.from === C.partner) showTyping(); });
    }

    // ---------- composer ----------
    if (C && ta && form) {
        var grow = function () { ta.style.height = "auto"; ta.style.height = Math.min(ta.scrollHeight, 140) + "px"; };

        ta.addEventListener("input", function () {
            grow();
            var now = Date.now();
            if (chat && chat.subscribed && now - lastTyping > 2500) {
                lastTyping = now;
                chat.trigger("client-typing", { from: C.me });
            }
        });

        // Enter sends, Shift+Enter adds a new line
        ta.addEventListener("keydown", function (e) {
            if (e.key === "Enter" && !e.shiftKey && !e.isComposing) {
                e.preventDefault();
                if (ta.value.trim() !== "") { form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event("submit", { cancelable: true })); }
            }
        });

        form.addEventListener("submit", function (e) {
            e.preventDefault();
            var text = ta.value.trim();
            if (!text) return;

            var fd = new FormData(form);
            fd.append("ajax", "1");

            // show it instantly, confirm it in the background
            var ts = Math.floor(Date.now() / 1000);
            var el = addBubble({ id: 0, ts: ts, body: text }, true, true);
            setSeen(false);
            toBottom();
            ta.value = "";
            grow();
            errEl.hidden = true;
            touchConvo(C.partner, { username: C.partnerUsername, name: C.partnerName, photo: C.partnerPhoto, initial: C.partnerInitial }, text, true, false);

            fetch(window.location.href, { method: "POST", body: fd, credentials: "same-origin" })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j && j.ok) { el.dataset.id = j.message.id; el.classList.remove("pending"); }
                    else { el.classList.remove("pending"); el.classList.add("failed"); errEl.textContent = (j && j.error) || "That message didn't send."; errEl.hidden = false; }
                })
                .catch(function () {
                    el.classList.remove("pending"); el.classList.add("failed");
                    errEl.textContent = "That message didn't send. Check your connection.";
                    errEl.hidden = false;
                });
        });

        if (!/Mobi|Android/i.test(navigator.userAgent)) ta.focus();
    }

    // ---------- fallback: only when the live connection isn't working ----------
    setInterval(function () {
        if (document.hidden) return;
        if (ta && ta.value.trim() !== "") return;
        if (RT && RT.pusher.connection.state === "connected") return;
        location.reload();
    }, 20000);
})();
</script>

</body>
</html>