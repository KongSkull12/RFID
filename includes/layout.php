<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function navItems(): array
{
    return [
        'index.php'          => ['label' => 'Dashboard',        'icon' => 'bi-speedometer2'],
        'parent_students.php'=> ['label' => 'My Students',      'icon' => 'bi-person-vcard'],
        'users.php'          => ['label' => 'Users',            'icon' => 'bi-people-fill'],
        'rfid.php'           => ['label' => 'RFID Cards',       'icon' => 'bi-broadcast-pin'],
        'gate.php'           => ['label' => 'Gate Scanner',     'icon' => 'bi-door-open-fill'],
        'logs.php'           => ['label' => 'User Logs',        'icon' => 'bi-file-earmark-bar-graph-fill'],
        'academics.php'      => ['label' => 'Academe',          'icon' => 'bi-mortarboard-fill'],
        'announcements.php'  => ['label' => 'Announcements',    'icon' => 'bi-megaphone-fill'],
        'tenant_settings.php'=> ['label' => 'School Settings',  'icon' => 'bi-gear-fill'],
        'sms_settings.php'   => ['label' => 'Parent SMS',       'icon' => 'bi-chat-dots-fill'],
    ];
}

function pageIcons(): array
{
    return [
        'index.php'           => 'bi-speedometer2',
        'parent_students.php' => 'bi-person-vcard',
        'users.php'           => 'bi-people-fill',
        'rfid.php'            => 'bi-broadcast-pin',
        'gate.php'            => 'bi-door-open-fill',
        'logs.php'            => 'bi-file-earmark-bar-graph-fill',
        'academics.php'       => 'bi-mortarboard-fill',
        'announcements.php'   => 'bi-megaphone-fill',
        'tenant_settings.php' => 'bi-gear-fill',
        'sms_settings.php'    => 'bi-chat-dots-fill',
        'user_edit.php'       => 'bi-person-lines-fill',
    ];
}

function renderHeader(string $title): void
{
    requireLogin();

    $current = basename($_SERVER['PHP_SELF'] ?? '');
    $flash      = flash('success');
    $errorFlash = flash('error');
    $auth = currentUser();
    $role = $auth['role'] ?? '';

    // Initials for school logo fallback
    $schoolName  = tenantName();
    $schoolLogo  = (string) (currentTenant()['logo_url'] ?? '');
    $initials    = '';
    foreach (explode(' ', $schoolName) as $word) {
        $initials .= strtoupper(mb_substr($word, 0, 1));
        if (strlen($initials) >= 2) break;
    }
    if ($initials === '') $initials = 'S';

    // User initials
    $userName   = (string) ($auth['name'] ?? '');
    $userInitials = '';
    foreach (explode(' ', $userName) as $word) {
        $userInitials .= strtoupper(mb_substr($word, 0, 1));
        if (strlen($userInitials) >= 2) break;
    }
    if ($userInitials === '') $userInitials = 'U';

    // Page icon
    $icons   = pageIcons();
    $pageIcon = $icons[$current] ?? 'bi-circle';

    echo '<!doctype html><html lang="en"><head>';
    echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' – ' . h(APP_NAME) . '</title>';

    // Preconnect + dns-prefetch to CDN origins (preconnect for modern browsers,
    // dns-prefetch as fallback for older ones)
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>';
    echo '<link rel="dns-prefetch" href="https://fonts.googleapis.com">';
    echo '<link rel="dns-prefetch" href="https://fonts.gstatic.com">';
    echo '<link rel="dns-prefetch" href="https://cdn.jsdelivr.net">';

    // Bootstrap CSS — critical for layout, load normally
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';

    // Our CSS — critical for theme/layout, load normally
    echo '<link href="' . h(BASE_URL . '/assets/ui.css') . '" rel="stylesheet">';

    // Google Fonts — non-blocking preload (text still visible with system font fallback)
    echo '<link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" as="style" onload="this.onload=null;this.rel=\'stylesheet\'">';
    echo '<noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"></noscript>';

    // Bootstrap Icons — non-blocking preload (icons are cosmetic, not layout-critical)
    echo '<link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" as="style" onload="this.onload=null;this.rel=\'stylesheet\'">';
    echo '<noscript><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"></noscript>';

    echo '</head><body>';

    echo '<div class="app-shell" id="appShell">';
    echo '<div class="sb-overlay" id="sbOverlay"></div>';

    /* ── Sidebar ─────────────────────────────────────────── */
    echo '<aside class="sidebar" id="appSidebar">';

    // Brand area
    echo '<div class="sb-brand">';
    if ($schoolLogo !== '') {
        echo '<div class="sb-logo sb-logo-img"><img src="' . h($schoolLogo) . '" alt="' . h($schoolName) . ' logo"></div>';
    } else {
        echo '<div class="sb-logo">' . h($initials) . '</div>';
    }
    echo '<div class="sb-brand-text">';
    echo '<div class="sb-school-name">' . h($schoolName) . '</div>';
    echo '<span class="sb-year-badge">SY ' . h(date('Y')) . '–' . h((string)((int)date('Y') + 1)) . '</span>';
    echo '</div>';
    // Mobile-only close button inside sidebar
    echo '<button class="sb-close-btn" id="sbCloseBtn" title="Close menu" aria-label="Close menu">'
        . '<i class="bi bi-x-lg"></i></button>';
    echo '</div>';

    // Navigation
    echo '<nav class="sb-nav">';

    $navItems = navItems();
    foreach ($navItems as $file => $meta) {
        $label = $meta['label'];
        $icon  = $meta['icon'];

        if ($role !== 'parent' && $file === 'parent_students.php') continue;
        if ($role === 'parent' && $file === 'index.php') continue;
        if ($role !== 'admin' && in_array($file, ['users.php','rfid.php','academics.php','tenant_settings.php','sms_settings.php'], true)) continue;
        if (!in_array($role, ['admin','employee'], true) && $file === 'gate.php') continue;

        if ($role === 'admin' && $file === 'users.php') {
            $usersActive = $current === 'users.php';
            $currentView = strtolower(trim((string)($_GET['users_view'] ?? 'student')));
            if (!in_array($currentView, ['student','parent','teacher','employee'], true)) $currentView = 'student';
            $expanded = $usersActive ? 'true' : 'false';

            echo '<button class="menu-toggle ' . ($usersActive ? 'active' : '') . '" type="button"'
                . ' data-bs-toggle="collapse" data-bs-target="#usersMenuCollapse"'
                . ' aria-expanded="' . $expanded . '" aria-controls="usersMenuCollapse">';
            echo '<span class="left">';
            echo '<span class="sb-icon"><i class="bi ' . h($icon) . '"></i></span>';
            echo '<span>' . h($label) . '</span>';
            echo '</span>';
            echo '<i class="bi bi-chevron-down chev"></i>';
            echo '</button>';

            echo '<div id="usersMenuCollapse" class="collapse ' . ($usersActive ? 'show' : '') . '">';
            echo '<div class="sub-links">';
            $subItems = ['student' => 'Students', 'parent' => 'Parents', 'teacher' => 'Teachers', 'employee' => 'Employees'];
            foreach ($subItems as $view => $sublabel) {
                $isActive = $usersActive && $currentView === $view ? 'active-sub' : '';
                echo '<a class="' . $isActive . '" href="' . h(appUrl('users.php', ['users_view' => $view])) . '">'
                    . h($sublabel) . '</a>';
            }
            echo '</div></div>';
            continue;
        }

        $active = $current === $file ? 'active' : '';
        echo '<a class="' . $active . '" href="' . h(appUrl($file)) . '" title="' . h($label) . '">';
        echo '<span class="sb-icon"><i class="bi ' . h($icon) . '"></i></span>';
        echo '<span>' . h($label) . '</span>';
        echo '</a>';
    }

    echo '</nav>'; // sb-nav

    // User block at bottom
    if ($auth) {
        echo '<div class="sb-user">';
        echo '<div class="sb-user-avatar">' . h($userInitials) . '</div>';
        echo '<div class="sb-user-info">';
        echo '<div class="sb-user-name">' . h($userName) . '</div>';
        echo '<div class="sb-user-role">' . h(roleLabel($role)) . '</div>';
        echo '</div>';
        echo '<a class="sb-logout" href="' . h(appUrl('logout.php')) . '" title="Logout">'
            . '<i class="bi bi-box-arrow-right"></i></a>';
        echo '</div>';
    }

    echo '</aside>'; // sidebar

    /* ── Main content ─────────────────────────────────────── */
    echo '<main class="main-content" id="mainContent">';

    // Topbar
    echo '<div class="topbar">';
    // Sidebar toggle button (desktop collapse + mobile open)
    echo '<button class="sb-toggle-btn" id="sbToggleBtn" title="Toggle sidebar" aria-label="Toggle sidebar">'
        . '<i class="bi bi-layout-sidebar-reverse"></i></button>';
    echo '<div class="tb-left">';
    echo '<div class="tb-page-icon"><i class="bi ' . h($pageIcon) . '"></i></div>';
    echo '<div>';
    echo '<div class="tb-title">' . h($title) . '</div>';
    echo '<div class="tb-subtitle"><i class="bi bi-building me-1"></i>' . h(tenantSlug()) . '</div>';
    echo '</div>';
    echo '</div>';
    echo '<div class="tb-right">';
    echo '<span class="tb-plan-badge"><i class="bi bi-stars me-1"></i>' . h((string)(currentTenant()['plan_name'] ?? 'Starter')) . '</span>';
    echo '<div class="tb-datetime"><div>' . h(date('M d, Y')) . '</div><div>' . h(date('h:i A')) . '</div></div>';
    echo '</div>';
    echo '</div>'; // topbar

    if ($flash) {
        echo '<div class="alert alert-success" data-auto-dismiss="true"><i class="bi bi-check-circle-fill me-2"></i>' . h($flash) . '</div>';
    }
    if ($errorFlash) {
        echo '<div class="alert alert-danger" data-auto-dismiss="true"><i class="bi bi-exclamation-triangle-fill me-2"></i>' . h($errorFlash) . '</div>';
    }

    echo '<div class="content-container">';
}

function renderFooter(): void
{
    echo '</div></main></div>'; // content-container + main-content + app-shell
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>';
    echo '<script src="' . h(BASE_URL . '/assets/ui.js') . '" defer></script>';
    echo '</body></html>';
}
