<?php
// Search, paging and row helpers shared by the admin pages.
// Needs auth.php (loaded first by every page) for db_run(), e(), csrf_field() and the verification helpers.

define('ADMIN_PER_PAGE', 20);    // rows per page on the "view all" pages
define('ADMIN_PEEK', 10);        // rows shown inline under a search box

// "ana_%" must be searched literally, not as wildcards
function admin_like($s) {
    return '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $s) . '%';
}

// "@ana  cruz" -> array('ana', 'cruz')   (every word has to match)
function admin_words($q, $max = 5) {
    $out = array();
    foreach (preg_split('/\s+/', trim((string)$q)) as $w) {
        $w = ltrim($w, '@');
        if ($w !== '') $out[] = $w;
        if (count($out) >= $max) break;
    }
    return $out;
}

function admin_page_param() {
    return max(1, (int)($_GET['page'] ?? 1));
}

// ---- verified students ----
// Matches first name, last name or username. A complete ID number also matches, through its fingerprint
// (the readable number isn't kept after review, so partial ID numbers can't be searched).
// Returns array('rows' => [...], 'total' => n).
function admin_verified_search($conn, $q, $limit, $offset) {
    $q = trim((string)$q);
    $where = "v.status = 'approved' AND u.is_verified = 1";
    $types = "";
    $params = array();

    if ($q !== '') {
        $parts = array();
        foreach (admin_words($q) as $w) {
            $like = admin_like($w);
            $parts[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ?)";
            $types .= "sss";
            array_push($params, $like, $like, $like);
        }
        $clause = $parts ? '(' . implode(' AND ', $parts) . ')' : '';
        if (verify_id_error($q) === "") {
            $clause = ($clause !== '' ? $clause . ' OR ' : '') . 'v.id_hash = ?';
            $types .= "s";
            $params[] = verify_hash_id($q);
        }
        if ($clause !== '') $where .= " AND (" . $clause . ")";
    }

    $from = "FROM verifications v JOIN users u ON u.id = v.user_id WHERE " . $where;

    $c = db_run($conn, "SELECT COUNT(*) AS n " . $from, $types, $params);
    $total = ($c['ok'] && $c['rows']) ? (int)$c['rows'][0]['n'] : 0;

    $rows = array();
    if ($total > 0 && $limit > 0) {
        $r = db_run($conn,
            "SELECT v.id, v.reviewed_at, u.username, u.first_name, u.last_name, u.course, u.year_level " . $from . "
             ORDER BY u.first_name ASC, u.last_name ASC, u.id ASC LIMIT ? OFFSET ?",
            $types . "ii", array_merge($params, array((int)$limit, (int)$offset)));
        $rows = $r['rows'];
    }
    return array('rows' => $rows, 'total' => $total);
}

// ---- clubs (approved and rejected; pending ones have their own list on the Clubs page) ----
// $status: 'all', 'approved' or 'rejected'. Matches club name, category, or who created it.
function admin_clubs_search($conn, $q, $status, $limit, $offset) {
    $q = trim((string)$q);
    $where = "c.status IN ('approved', 'rejected')";
    if ($status === 'approved') $where = "c.status = 'approved'";
    if ($status === 'rejected') $where = "c.status = 'rejected'";
    $types = "";
    $params = array();

    foreach (admin_words($q) as $w) {
        $like = admin_like($w);
        $where .= " AND (c.name LIKE ? OR c.category LIKE ? OR u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
        $types .= "sssss";
        array_push($params, $like, $like, $like, $like, $like);
    }

    $from = "FROM clubs c LEFT JOIN users u ON u.id = c.created_by WHERE " . $where;

    $cnt = db_run($conn, "SELECT COUNT(*) AS n " . $from, $types, $params);
    $total = ($cnt['ok'] && $cnt['rows']) ? (int)$cnt['rows'][0]['n'] : 0;

    $rows = array();
    if ($total > 0 && $limit > 0) {
        $r = db_run($conn,
            "SELECT c.id, c.name, c.category, c.status, c.review_note, c.reviewed_at, u.username, u.first_name, u.last_name " . $from . "
             ORDER BY c.name ASC, c.id ASC LIMIT ? OFFSET ?",
            $types . "ii", array_merge($params, array((int)$limit, (int)$offset)));
        $rows = $r['rows'];
    }
    return array('rows' => $rows, 'total' => $total);
}

// ---- markup ----

// The same pill search bar the Students page uses. $hidden = extra values to keep (like a status filter).
function admin_search_form($action, $q, $placeholder, $label, $hidden = array()) {
    $h = '<form method="GET" action="' . e($action) . '" class="dir-search va-search" role="search">';
    foreach ($hidden as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    $h .= '<div class="dir-field">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>'
        . '<input type="search" name="q" maxlength="60" placeholder="' . e($placeholder) . '" value="' . e($q) . '" aria-label="' . e($label) . '" autocomplete="off">'
        . '</div>'
        . '<button type="submit" class="btn-sm btn-dark">Search</button>'
        . '</form>';
    return $h;
}

// Previous / Next with "Page 2 of 40". $params = the query values to keep in the links (empty ones left out).
function admin_pager($base, $params, $page, $pages) {
    if ($pages <= 1) return '';
    $url = function ($p) use ($base, $params) {
        $params['page'] = $p;
        return $base . '?' . http_build_query($params);
    };
    $h = '<nav class="dir-pager" aria-label="Pages">';
    if ($page > 1) $h .= '<a href="' . e($url($page - 1)) . '" class="btn-sm btn-ghost">Previous</a>';
    $h .= '<span class="dir-page">Page ' . (int)$page . ' of ' . (int)$pages . '</span>';
    if ($page < $pages) $h .= '<a href="' . e($url($page + 1)) . '" class="btn-sm btn-ghost">Next</a>';
    return $h . '</nav>';
}

// One verified student with a Remove button. $back says which page to return to afterwards.
function admin_verified_row($s, $back) {
    $name = trim($s['first_name'] . ' ' . $s['last_name']);
    $line = array();
    if (!empty($s['course'])) $line[] = e($s['course']);
    if (!empty($s['year_level'])) $line[] = e($s['year_level']);
    if (!empty($s['reviewed_at'])) $line[] = 'verified ' . e(date('M j, Y', strtotime($s['reviewed_at'])));

    $h  = '<div class="club-item"><div class="club-head"><strong>';
    $h .= '<a href="profile?u=' . e(urlencode($s['username'])) . '">' . e($name) . '</a></strong>';
    $h .= '<span class="badge approved">Verified</span></div>';
    $h .= '<div class="club-meta">@' . e($s['username']) . ($line ? ' &middot; ' . implode(' &middot; ', $line) : '') . '</div>';
    $h .= '<form method="POST" action="admin-verifications" class="actions" data-confirm="They will not be able to create new clubs, events or projects until they are verified again." data-confirm-title="Remove this verification?" data-confirm-ok="Remove" data-confirm-tone="warning" data-confirm-icon="userminus">';
    $h .= csrf_field();
    $h .= '<input type="hidden" name="vid" value="' . (int)$s['id'] . '">';
    $h .= '<input type="hidden" name="back" value="' . e($back) . '">';
    $h .= '<input type="text" name="note" maxlength="255" placeholder="Reason (optional)" aria-label="Reason for removing verification">';
    $h .= '<button type="submit" name="action" value="revoke" class="btn-sm btn-reject">Remove</button>';
    $h .= '</form></div>';
    return $h;
}

// One approved or rejected club
// $back = the admin page to return to after a delete (leave empty to hide the Delete button).
function admin_club_row($c, $back = '') {
    $by = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
    $name = e($c['name']);
    if ($c['status'] === 'approved') {
        $name = '<a href="club?id=' . (int)$c['id'] . '">' . $name . '</a>';
    }
    $h  = '<div class="club-item"><div class="club-head"><strong>' . $name . '</strong>';
    $h .= '<span class="badge ' . e($c['status']) . '">' . e(ucfirst($c['status'])) . '</span></div>';
    $h .= '<div class="club-meta">' . e($c['category']);
    if ($by !== '') {
        $h .= ' &middot; by ' . e($by) . (!empty($c['username']) ? ' (@' . e($c['username']) . ')' : '');
    }
    if (!empty($c['reviewed_at'])) $h .= ' &middot; ' . e(date('M j, Y', strtotime($c['reviewed_at'])));
    if (!empty($c['review_note'])) $h .= ' &middot; Note: ' . e($c['review_note']);
    $h .= '</div>';
    if ($back !== '') {
        $h .= '<form method="POST" action="admin-club-list" class="actions" data-confirm="Its members, announcements, events and chat will be removed. This cannot be undone." data-confirm-title="Delete this club permanently?" data-confirm-ok="Delete club" data-confirm-tone="danger" data-confirm-icon="trash">';
        $h .= csrf_field();
        $h .= '<input type="hidden" name="club_id" value="' . (int)$c['id'] . '">';
        $h .= '<input type="hidden" name="back" value="' . e($back) . '">';
        $h .= '<input type="text" name="note" maxlength="255" placeholder="Reason (optional)" aria-label="Reason for deleting this club">';
        $h .= '<button type="submit" name="action" value="delete" class="btn-sm btn-reject">Delete club</button>';
        $h .= '</form>';
    }
    $h .= '</div>';
    return $h;
}

// ---- projects (no review step, so every project is listed) ----
// Matches project title, tagline, category, or the founder's name / username.
function admin_projects_search($conn, $q, $limit, $offset) {
    $q = trim((string)$q);
    $where = "1 = 1";
    $types = "";
    $params = array();

    foreach (admin_words($q) as $w) {
        $like = admin_like($w);
        $where .= " AND (p.title LIKE ? OR p.tagline LIKE ? OR p.category LIKE ? OR u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
        $types .= "ssssss";
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    $from = "FROM projects p LEFT JOIN users u ON u.id = p.owner_id WHERE " . $where;

    $cnt = db_run($conn, "SELECT COUNT(*) AS n " . $from, $types, $params);
    $total = ($cnt['ok'] && $cnt['rows']) ? (int)$cnt['rows'][0]['n'] : 0;

    $rows = array();
    if ($total > 0 && $limit > 0) {
        $r = db_run($conn,
            "SELECT p.id, p.title, p.category, p.stage, p.created_at, u.username, u.first_name, u.last_name " . $from . "
             ORDER BY p.title ASC, p.id ASC LIMIT ? OFFSET ?",
            $types . "ii", array_merge($params, array((int)$limit, (int)$offset)));
        $rows = $r['rows'];
    }
    return array('rows' => $rows, 'total' => $total);
}

// ---- events (no review step, so every event is listed) ----
// Matches event title, location, the club's name, or who posted it.
function admin_events_search($conn, $q, $limit, $offset) {
    $q = trim((string)$q);
    $where = "1 = 1";
    $types = "";
    $params = array();

    foreach (admin_words($q) as $w) {
        $like = admin_like($w);
        $where .= " AND (e.title LIKE ? OR e.location LIKE ? OR c.name LIKE ? OR u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
        $types .= "ssssss";
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    $from = "FROM events e LEFT JOIN clubs c ON c.id = e.club_id LEFT JOIN users u ON u.id = e.created_by WHERE " . $where;

    $cnt = db_run($conn, "SELECT COUNT(*) AS n " . $from, $types, $params);
    $total = ($cnt['ok'] && $cnt['rows']) ? (int)$cnt['rows'][0]['n'] : 0;

    $rows = array();
    if ($total > 0 && $limit > 0) {
        $r = db_run($conn,
            "SELECT e.id, e.title, e.start_at, e.end_at, e.status, c.name AS club_name, u.username, u.first_name, u.last_name " . $from . "
             ORDER BY e.start_at DESC, e.id DESC LIMIT ? OFFSET ?",
            $types . "ii", array_merge($params, array((int)$limit, (int)$offset)));
        $rows = $r['rows'];
    }
    return array('rows' => $rows, 'total' => $total);
}

// One project with a Delete button. $back = the admin page to return to afterwards (empty hides the button).
function admin_project_row($p, $back = '') {
    $by = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
    $h  = '<div class="club-item"><div class="club-head"><strong>';
    $h .= '<a href="project?id=' . (int)$p['id'] . '">' . e($p['title']) . '</a></strong>';
    if (!empty($p['stage'])) $h .= '<span class="badge approved">' . e(function_exists('project_stage_label') ? project_stage_label($p['stage']) : ucfirst($p['stage'])) . '</span>';
    $h .= '</div>';
    $h .= '<div class="club-meta">' . e($p['category']);
    if ($by !== '') {
        $h .= ' &middot; by ' . e($by) . (!empty($p['username']) ? ' (@' . e($p['username']) . ')' : '');
    }
    if (!empty($p['created_at'])) $h .= ' &middot; ' . e(date('M j, Y', strtotime($p['created_at'])));
    $h .= '</div>';
    if ($back !== '') {
        $h .= '<form method="POST" action="admin-project-list" class="actions" data-confirm="Its team, roles and updates will be removed. This cannot be undone." data-confirm-title="Delete this project permanently?" data-confirm-ok="Delete project" data-confirm-tone="danger" data-confirm-icon="trash">';
        $h .= csrf_field();
        $h .= '<input type="hidden" name="project_id" value="' . (int)$p['id'] . '">';
        $h .= '<input type="hidden" name="back" value="' . e($back) . '">';
        $h .= '<input type="text" name="note" maxlength="255" placeholder="Reason (optional)" aria-label="Reason for deleting this project">';
        $h .= '<button type="submit" name="action" value="delete" class="btn-sm btn-reject">Delete project</button>';
        $h .= '</form>';
    }
    $h .= '</div>';
    return $h;
}

// One event with a Delete button. $back = the admin page to return to afterwards (empty hides the button).
function admin_event_row($ev, $back = '') {
    $by = trim(($ev['first_name'] ?? '') . ' ' . ($ev['last_name'] ?? ''));
    if ($ev['status'] !== 'active') {
        $badge = '<span class="badge rejected">Cancelled</span>';
    } else {
        $state = function_exists('event_state') ? event_state($ev) : 'upcoming';
        $badge = '<span class="badge ' . ($state === 'over' ? '' : 'approved') . '">' . e(ucfirst($state)) . '</span>';
    }
    $when = function_exists('event_dt') ? event_dt($ev['start_at'])->format('M j, Y g:i A') : date('M j, Y g:i A', strtotime($ev['start_at']));

    $h  = '<div class="club-item"><div class="club-head"><strong>';
    $h .= '<a href="event?id=' . (int)$ev['id'] . '">' . e($ev['title']) . '</a></strong>' . $badge . '</div>';
    $h .= '<div class="club-meta">' . e($when);
    if (!empty($ev['club_name'])) $h .= ' &middot; ' . e($ev['club_name']);
    if ($by !== '') {
        $h .= ' &middot; by ' . e($by) . (!empty($ev['username']) ? ' (@' . e($ev['username']) . ')' : '');
    }
    $h .= '</div>';
    if ($back !== '') {
        $h .= '<form method="POST" action="admin-event-list" class="actions" data-confirm="Everyone who is going will be told. This cannot be undone." data-confirm-title="Delete this event permanently?" data-confirm-ok="Delete event" data-confirm-tone="danger" data-confirm-icon="trash">';
        $h .= csrf_field();
        $h .= '<input type="hidden" name="event_id" value="' . (int)$ev['id'] . '">';
        $h .= '<input type="hidden" name="back" value="' . e($back) . '">';
        $h .= '<input type="text" name="note" maxlength="255" placeholder="Reason (optional)" aria-label="Reason for deleting this event">';
        $h .= '<button type="submit" name="action" value="delete" class="btn-sm btn-reject">Delete event</button>';
        $h .= '</form>';
    }
    $h .= '</div>';
    return $h;
}