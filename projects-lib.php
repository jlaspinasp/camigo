<?php
// Shared helpers for Camigo Projects (post an idea, find teammates, build it together).
// Pages must call session_start() and require db.php + auth.php first.

require_once __DIR__ . '/events-lib.php';

define('PROJECT_MAX_ROLES', 6);          // roles a project can list
define('PROJECT_MAX_SPOTS', 10);         // spots per role
define('PROJECT_MAX_PENDING', 10);       // open applications one person can have
define('PROJECT_PER_PAGE', 40);

// ---------- what a project can be ----------

// category => icon name
function project_categories() {
    return array(
        'App & Software'           => 'code',
        'Startup & Business'       => 'briefcase',
        'Research & Academic'      => 'flask',
        'Design & Creative'        => 'palette',
        'Community & Social Good'  => 'heart',
        'Hackathon & Competition'  => 'trophy',
        'Other'                    => 'layers',
    );
}

// ---------- icons ----------
function project_icon($name) {
    $p = array(
        'code'      => '<path d="M8.5 8L4 12l4.5 4M15.5 8L20 12l-4.5 4M13.5 5l-3 14"/>',
        'briefcase' => '<rect x="3.5" y="7.5" width="17" height="12" rx="3"/><path d="M9 7.5V6a2 2 0 012-2h2a2 2 0 012 2v1.5M3.5 13h17"/>',
        'flask'     => '<path d="M9.5 3.5h5M10.5 3.5v5.2L5.4 17a2.4 2.4 0 002.1 3.5h9a2.4 2.4 0 002.1-3.5l-5.1-8.3V3.5M8 14h8"/>',
        'palette'   => '<path d="M12 3.5a8.5 8.5 0 100 17c1.4 0 2-1 1.6-2.1-.4-1 .3-2.1 1.5-2.1H17a3.5 3.5 0 003.5-3.5C20.5 7 16.7 3.5 12 3.5z"/><circle cx="8" cy="11" r="1"/><circle cx="11" cy="7.5" r="1"/><circle cx="15.5" cy="8.5" r="1"/>',
        'heart'     => '<path d="M12 20s-7.5-4.6-7.5-10.2A4.3 4.3 0 0112 7.3a4.3 4.3 0 017.5 2.5C19.5 15.4 12 20 12 20z"/>',
        'trophy'    => '<path d="M8 4h8v5a4 4 0 01-8 0V4zM8 6H5a3 3 0 003 4M16 6h3a3 3 0 01-3 4M12 13v4M8.5 20h7M10 17h4"/>',
        'layers'    => '<path d="M12 4l8.5 4.5L12 13 3.5 8.5 12 4z"/><path d="M3.5 12.5L12 17l8.5-4.5M3.5 16L12 20.5l8.5-4.5"/>',
        'rocket'    => '<path d="M12 3c3.5 1.6 6 5 6 9.5L15.5 15h-7L6 12.5C6 8 8.5 4.6 12 3z"/><circle cx="12" cy="9.5" r="1.6"/><path d="M9 15l-2 4.5 3.2-1.4M15 15l2 4.5-3.2-1.4"/>',
        'bulb'      => '<path d="M9 18h6M10 21h4M12 3a6 6 0 00-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0012 3z"/>',
        'send'      => '<path d="M20.5 4L3.5 11l6.2 2.3L12 20l8.5-16zM9.7 13.3L20.5 4"/>',
        'link'      => '<path d="M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1"/>',
        'sparkle'   => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3zM19 16l.7 1.8L21.5 18.5l-1.8.7L19 21l-.7-1.8-1.8-.7 1.8-.7L19 16z"/>',
        'seat'      => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.6 3-5.8 7-5.8s7 2.2 7 5.8"/>',
        'chat'      => '<path d="M5 4.5h14a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5h-8L6.5 20.5V16.5H5A1.5 1.5 0 013.5 15V6A1.5 1.5 0 015 4.5z"/>',
        'logout'    => '<path d="M10 5H6a1.5 1.5 0 00-1.5 1.5v11A1.5 1.5 0 006 19h4M14 8l4 4-4 4M18 12H9"/>',
    );
    if (!isset($p[$name])) return event_icon($name);
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p[$name] . '</svg>';
}

function project_category_icon($cat) {
    $c = project_categories();
    return project_icon(isset($c[$cat]) ? $c[$cat] : 'layers');
}

// 0, 1 or 2: which colour the cover uses (stable per project)
function project_tone($id) {
    return (int)$id % 3;
}

// ---------- banners ----------

// Has projects-banner.sql been run? (projects.banner holds the file name of an uploaded project banner)
function projects_banner_ready($conn) {
    static $ok = null;
    if ($ok === null) {
        $a = @mysqli_query($conn, "SHOW COLUMNS FROM projects LIKE 'banner'");
        $ok = ($a && mysqli_num_rows($a) > 0);
    }
    return $ok;
}

function project_banner_dir() {
    return __DIR__ . '/uploads/projects';
}

// Reads the uploaded banner field. Returns the saved file name, or null (sets $error only if something went wrong;
// no file chosen is not an error). Same JPG/PNG/WebP re-draw as club covers (10:3 crop).
function project_banner_upload($field, &$error) {
    $up = $_FILES[$field] ?? null;
    if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($up['error'] !== UPLOAD_ERR_OK) { $error = "That image couldn't be uploaded. Please try a smaller file."; return null; }
    if ($up['size'] > 10 * 1024 * 1024) { $error = "Images can be up to 10 MB."; return null; }
    return save_club_image($up['tmp_name'], 'cover', $error, project_banner_dir());
}

function project_banner_delete($name) {
    delete_club_image($name, project_banner_dir());
}

// The file name of a project's banner (read it before the project row is deleted), or null.
function project_banner_of($conn, $projectId) {
    if (!projects_banner_ready($conn)) return null;
    $r = db_run($conn, "SELECT banner FROM projects WHERE id = ? LIMIT 1", "i", array((int)$projectId));
    return ($r['rows'] && !empty($r['rows'][0]['banner'])) ? $r['rows'][0]['banner'] : null;
}

// Web path of the uploaded banner, or '' (then the project keeps its coloured cover).
function project_banner_url($p) {
    return (!empty($p['banner']) && club_image_ok($p['banner'])) ? 'uploads/projects/' . $p['banner'] : '';
}

function pj_lower($s) {
    return function_exists('mb_strtolower') ? mb_strtolower((string)$s, 'UTF-8') : strtolower((string)$s);
}

function project_tags($csv) {
    return array_values(array_filter(array_map('trim', explode(',', (string)$csv)), 'strlen'));
}

// ---------- reading projects ----------

// Roles with how many spots are taken. Returns array of rows: id, project_id, title, spots, filled
function project_roles($conn, $projectId) {
    $r = db_run($conn,
        "SELECT r.id, r.project_id, r.title, r.spots,
                (SELECT COUNT(*) FROM project_members m WHERE m.role_id = r.id AND m.status = 'active') AS filled
         FROM project_roles r WHERE r.project_id = ? ORDER BY r.id ASC",
        "i", array((int)$projectId));
    return $r['rows'];
}

function project_open_spots($roles) {
    $n = 0;
    foreach ($roles as $r) {
        $n += max(0, (int)$r['spots'] - (int)$r['filled']);
    }
    return $n;
}

// array(label, css class) describing whether people can still join
function project_status($p, $roles) {
    if (empty($p['is_open'])) return array('Not recruiting', 'closed');
    if ($roles && project_open_spots($roles) === 0) return array('Team full', 'full');
    return array('Open to teammates', 'open');
}

// One project with its founder and club. Returns the row or null.
function project_get($conn, $projectId) {
    $r = db_run($conn,
        "SELECT p.id, p.owner_id, p.club_id, p.title, p.tagline, p.description, p.category, p.skills, p.link, p.is_open,
                " . (projects_banner_ready($conn) ? "p.banner," : "") . "
                UNIX_TIMESTAMP(p.created_at) AS created_ts, UNIX_TIMESTAMP(p.updated_at) AS updated_ts,
                u.username, u.first_name, u.last_name, u.course, u.year_level, u.photo, u.profile_visibility,
                c.name AS club_name
         FROM projects p
         JOIN users u ON u.id = p.owner_id
         LEFT JOIN clubs c ON c.id = p.club_id AND c.status = 'approved'
         WHERE p.id = ? LIMIT 1",
        "i", array((int)$projectId));
    return $r['rows'] ? $r['rows'][0] : null;
}

// Lists for the browse page, the dashboard and profiles.
// $where may use ? marks (pass their types and values). Columns are prefixed p. (projects).
// Every row gets: roles (with filled), faces (a few team members), team_n, my_role, my_status.
function project_query($conn, $meId, $where, $types, $params, $order, $limit) {
    $sql = "SELECT p.id, p.owner_id, p.club_id, p.title, p.tagline, p.category, p.skills, p.is_open,
                   " . (projects_banner_ready($conn) ? "p.banner," : "") . "
                   UNIX_TIMESTAMP(p.created_at) AS created_ts,
                   u.username, u.first_name, u.last_name, u.photo, u.profile_visibility,
                   c.name AS club_name,
                   (SELECT COUNT(*) FROM project_members t WHERE t.project_id = p.id AND t.status = 'active') AS team_n,
                   mine.role AS my_role, mine.status AS my_status
            FROM projects p
            JOIN users u ON u.id = p.owner_id
            LEFT JOIN clubs c ON c.id = p.club_id AND c.status = 'approved'
            LEFT JOIN project_members mine ON mine.project_id = p.id AND mine.user_id = ?";
    $allTypes  = "i" . $types;
    $allParams = array_merge(array((int)$meId), $params);

    if ($where !== '') $sql .= " WHERE " . $where;
    $sql .= " ORDER BY " . $order . " LIMIT " . (int)$limit;

    $res = db_run($conn, $sql, $allTypes, $allParams);
    if (!$res['ok'] || !$res['rows']) return $res;

    $ids = array();
    foreach ($res['rows'] as $row) $ids[] = (int)$row['id'];
    $in = implode(',', $ids);

    $rolesBy = array();
    $rr = db_run($conn,
        "SELECT r.id, r.project_id, r.title, r.spots,
                (SELECT COUNT(*) FROM project_members m WHERE m.role_id = r.id AND m.status = 'active') AS filled
         FROM project_roles r WHERE r.project_id IN ($in) ORDER BY r.id ASC");
    foreach ($rr['rows'] as $x) $rolesBy[(int)$x['project_id']][] = $x;

    $facesBy = array();
    $fr = db_run($conn,
        "SELECT m.project_id, u.first_name, u.photo, u.profile_visibility
         FROM project_members m JOIN users u ON u.id = m.user_id
         WHERE m.project_id IN ($in) AND m.status = 'active'
         ORDER BY (m.role = 'owner') DESC, m.created_at ASC");
    foreach ($fr['rows'] as $x) {
        $pid = (int)$x['project_id'];
        if (isset($facesBy[$pid]) && count($facesBy[$pid]) >= 4) continue;
        // people with a private profile only show as their initial
        if (!empty($x['profile_visibility']) && $x['profile_visibility'] !== 'public') $x['photo'] = null;
        $facesBy[$pid][] = $x;
    }

    foreach ($res['rows'] as $i => $row) {
        $pid = (int)$row['id'];
        $res['rows'][$i]['roles'] = isset($rolesBy[$pid]) ? $rolesBy[$pid] : array();
        $res['rows'][$i]['faces'] = isset($facesBy[$pid]) ? $facesBy[$pid] : array();
    }
    return $res;
}

// "Design" matches "Design & Creative" and "UI design"; returns the person's interests that fit this project
function project_matches($skillsCsv, $category, $roles, $interests) {
    $tags = project_tags($skillsCsv);
    $tags[] = $category;
    foreach ($roles as $r) $tags[] = $r['title'];

    $found = array();
    foreach ($interests as $i) {
        $il = pj_lower($i);
        if ($il === '') continue;
        foreach ($tags as $t) {
            $tl = pj_lower($t);
            $hit = ($tl === $il);
            if (!$hit && str_len($il) >= 4 && strpos($tl, $il) !== false) $hit = true;
            if (!$hit && str_len($tl) >= 4 && strpos($il, $tl) !== false) $hit = true;
            if ($hit) { $found[] = $i; break; }
        }
    }
    return $found;
}

// ---------- the form ----------

// Reads and checks the create / edit form.
// Returns the clean values, or null (and sets $err).
function project_clean($post, &$err) {
    $clean = function ($s) { return trim(preg_replace('/[ \t]+/', ' ', (string)$s)); };

    $title   = $clean(preg_replace('/\s+/', ' ', (string)($post['title'] ?? '')));
    $tagline = $clean(preg_replace('/\s+/', ' ', (string)($post['tagline'] ?? '')));
    $desc    = trim(str_replace("\r\n", "\n", (string)($post['description'] ?? '')));
    $desc    = preg_replace("/\n{3,}/", "\n\n", $desc);
    $cat     = (string)($post['category'] ?? '');

    if (str_len($title) < 3 || str_len($title) > 80) { $err = "Give your project a name between 3 and 80 characters."; return null; }
    if (str_len($tagline) < 10 || str_len($tagline) > 120) { $err = "Write a one-line pitch between 10 and 120 characters."; return null; }
    if (!isset(project_categories()[$cat])) { $err = "Please pick a category."; return null; }
    if (str_len($desc) < 30) { $err = "Tell people a bit more about the project. A few sentences helps them decide."; return null; }
    if (str_len($desc) > 2000) { $err = "The description can be up to 2000 characters."; return null; }

    // skills & tools (same rules as profile interests)
    $skills = parse_interests($post['skills'] ?? '', $e1);
    if ($skills === null && !empty($e1)) {
        $err = str_ireplace(array('interests', 'interest'), array('skills', 'skill'), $e1);
        return null;
    }
    if ($skills === '') $skills = null;

    $link = null;
    if (trim((string)($post['link'] ?? '')) !== '') {
        $link = normalize_url($post['link'], '', 'project', $e2);
        if ($link === null) { $err = $e2 !== null ? $e2 : "That project link doesn't look right."; return null; }
    }

    // roles the founder is looking for
    $ids    = isset($post['role_id'])    && is_array($post['role_id'])    ? array_values($post['role_id'])    : array();
    $titles = isset($post['role_title']) && is_array($post['role_title']) ? array_values($post['role_title']) : array();
    $spots  = isset($post['role_spots']) && is_array($post['role_spots']) ? array_values($post['role_spots']) : array();

    $roles = array();
    $seen  = array();
    foreach ($titles as $i => $t) {
        if (!is_string($t)) continue;
        $t = $clean($t);
        if ($t === '') continue;
        if (str_len($t) > 40) { $err = "Role names can be up to 40 characters."; return null; }
        if (!preg_match('/^[\p{L}\p{N} &+#.\/\'()-]+$/u', $t)) { $err = "Role names can use letters, numbers and these symbols: & + # . / ( ) - '"; return null; }
        $k = pj_lower($t);
        if (isset($seen[$k])) { $err = "List each role once and raise its spots instead."; return null; }
        $seen[$k] = true;

        $n = (int)($spots[$i] ?? 1);
        if ($n < 1) $n = 1;
        if ($n > PROJECT_MAX_SPOTS) $n = PROJECT_MAX_SPOTS;

        $roles[] = array('id' => (int)($ids[$i] ?? 0), 'title' => $t, 'spots' => $n);
    }
    if (count($roles) > PROJECT_MAX_ROLES) { $err = "You can list up to " . PROJECT_MAX_ROLES . " roles."; return null; }

    return array(
        'title'       => $title,
        'tagline'     => $tagline,
        'description' => $desc,
        'category'    => $cat,
        'skills'      => $skills,
        'link'        => $link,
        'is_open'     => empty($post['is_open']) ? 0 : 1,
        'roles'       => $roles,
    );
}

// ---------- notifications ----------

// Tells everyone on the team (except one person) something happened
function project_notify_team($conn, $projectId, $exceptUserId, $type, $message, $link) {
    db_run($conn,
        "INSERT INTO notifications (user_id, type, message, link)
         SELECT user_id, ?, ?, ? FROM project_members
         WHERE project_id = ? AND status = 'active' AND user_id <> ?",
        "sssii", array($type, str_cut($message, 255), $link, (int)$projectId, (int)$exceptUserId));
}

// Tells the members of a club that one of its officers started a project
function project_notify_club($conn, $clubId, $exceptUserId, $message, $link) {
    db_run($conn,
        "INSERT INTO notifications (user_id, type, message, link)
         SELECT user_id, 'project_new', ?, ? FROM club_members
         WHERE club_id = ? AND status = 'active' AND user_id <> ?",
        "ssii", array(str_cut($message, 255), $link, (int)$clubId, (int)$exceptUserId));
}

// ---------- one project card ----------
// $interests = the viewer's interests (array) so we can show "Matches your interests"
function render_project_card($p, $interests = array()) {
    $id     = (int)$p['id'];
    $tone   = project_tone($id);
    $roles  = $p['roles'] ?? array();
    $faces  = $p['faces'] ?? array();
    $spots  = project_open_spots($roles);
    $status = project_status($p, $roles);
    $tags   = project_tags($p['skills']);
    $match  = $interests ? project_matches($p['skills'], $p['category'], $roles, $interests) : array();
    $isOwner  = (($p['my_role'] ?? '') === 'owner' && ($p['my_status'] ?? '') === 'active');
    $isMember = (($p['my_status'] ?? '') === 'active');
    $isPending = (($p['my_status'] ?? '') === 'pending');
    $team   = (int)$p['team_n'];
    $showRoles = array_slice($roles, 0, 2);
    ?>
    <?php $bnUrl = project_banner_url($p); ?>
    <article class="pj-card">
        <div class="pj-cover t<?php echo $tone; ?><?php echo $bnUrl !== '' ? ' has-banner' : ''; ?>"<?php if ($bnUrl !== '') { ?> style="--pj-banner:url('<?php echo e($bnUrl); ?>')"<?php } ?>>
            <span class="pj-cover-ic"><?php echo project_category_icon($p['category']); ?></span>
            <div class="pj-cover-top">
                <?php if ($match) { ?><span class="badge pj-match" title="Matches: <?php echo e(implode(', ', array_slice($match, 0, 3))); ?>"><?php echo project_icon('sparkle'); ?>Matches your interests</span><?php } ?>
            </div>
            <span class="pj-cover-cat"><?php echo e($p['category']); ?></span>
        </div>

        <div class="pj-body">
            <?php if (!empty($p['club_name'])) { ?>
                <a class="pj-club" href="club?id=<?php echo (int)$p['club_id']; ?>"><?php echo project_icon('users'); ?><?php echo e($p['club_name']); ?></a>
            <?php } ?>

            <h3><a class="pj-stretch" href="project?id=<?php echo $id; ?>"><?php echo e($p['title']); ?></a></h3>
            <p class="pj-tag"><?php echo e($p['tagline']); ?></p>

            <?php if ($tags) { ?>
                <div class="pj-skills">
                    <?php foreach (array_slice($tags, 0, 3) as $t) { ?><span class="pj-skill"><?php echo e($t); ?></span><?php } ?>
                    <?php if (count($tags) > 3) { ?><span class="pj-skill more">+<?php echo count($tags) - 3; ?> more</span><?php } ?>
                </div>
            <?php } ?>

            <?php if ($showRoles) { ?>
                <div class="pj-looking">
                    <small>Looking for</small>
                    <div class="pj-roles-mini">
                        <?php foreach ($showRoles as $r) {
                            $left = max(0, (int)$r['spots'] - (int)$r['filled']);
                        ?>
                            <span class="pj-role<?php echo $left === 0 ? ' full' : ''; ?>"><span class="pj-role-t"><?php echo e($r['title']); ?></span><span class="pj-role-n"><?php echo $left === 0 ? 'Filled' : '×' . $left; ?></span></span>
                        <?php } ?>
                        <?php if (count($roles) > 2) { ?><span class="pj-role more">+<?php echo count($roles) - 2; ?> more</span><?php } ?>
                    </div>
                </div>
            <?php } ?>

            <div class="pj-spacer"></div>

            <div class="pj-foot">
                <div class="pj-by">
                    <?php if ($faces) { ?>
                        <span class="faces">
                            <?php foreach ($faces as $f) { ?><span class="face"><?php echo avatar_inner($f); ?></span><?php } ?>
                        </span>
                    <?php } ?>
                    <span class="pj-by-txt">
                        <strong><?php echo e(full_name($p)); ?></strong>
                        <em><?php echo $team; ?> on the team</em>
                    </span>
                </div>

                <?php if ($isOwner) { ?>
                    <span class="status-text status-role">You're the founder</span>
                <?php } elseif ($isMember) { ?>
                    <span class="status-text status-role">On the team</span>
                <?php } elseif ($isPending) { ?>
                    <span class="status-text status-sent">Applied</span>
                <?php } elseif ($status[1] === 'open') { ?>
                    <span class="status-text"><?php echo $roles ? ($spots === 1 ? '1 spot open' : $spots . ' spots open') : 'Open'; ?></span>
                <?php } else { ?>
                    <span class="status-text"><?php echo e($status[0]); ?></span>
                <?php } ?>
            </div>
        </div>
    </article>
    <?php
}