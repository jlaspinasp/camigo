<?php
// Live connection for messages. Expects $rtUserId and $unreadMsg.
// Does nothing if Pusher isn't set up, so the site works either way.
if (function_exists('pusher_enabled') && pusher_enabled() && !empty($rtUserId)) {
    $rtMuted = (isset($conn) && function_exists('chat_muted_ids')) ? chat_muted_ids($conn, $rtUserId) : array('u' => array(), 'g' => array());
?>
<script src="https://js.pusher.com/8.4.0/pusher.min.js"></script>
<script>
(function () {
    if (!window.Pusher) return;

    var pusher = new Pusher(<?php echo json_encode(PUSHER_KEY); ?>, {
        cluster: <?php echo json_encode(PUSHER_CLUSTER); ?>,
        channelAuthorization: { endpoint: "pusher-auth", transport: "ajax" }
    });

    var RT = window.CamigoRT = { pusher: pusher, me: <?php echo (int)$rtUserId; ?>, open: '', muted: <?php echo json_encode($rtMuted); ?> };
    var dot = document.getElementById("msg-dot");
    var count = <?php echo (int)$unreadMsg; ?>;

    RT.setBadge = function (n) {
        count = Math.max(0, n | 0);
        if (!dot) return;
        if (count > 0) { dot.textContent = count > 9 ? "9+" : count; dot.hidden = false; }
        else { dot.hidden = true; }
    };

    RT.userChannel = pusher.subscribe("private-user-" + RT.me);

    // a new message: bump the badge unless you're looking at that chat or you muted it
    RT.userChannel.bind("new-message", function (d) {
        var k = d.k === "g" ? "g" : "u", id = k === "g" ? d.gid : d.from;
        var watching = RT.open === k + ":" + id && !document.hidden;
        var muted = (RT.muted[k] || []).indexOf(id) > -1;
        if (!watching && !muted) RT.setBadge(count + 1);
    });
})();
</script>
<?php } ?>
<?php include("chat-popout.php"); // the floating Messages window, on every page that has the top bar ?>