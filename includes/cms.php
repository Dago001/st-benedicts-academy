<?php
// includes/cms.php - lightweight content management: editable text/images, hero slides and custom pages.
// Public pages call cms('group.key'); when nothing has been saved the built-in default is used, so the
// site always renders even before the database is migrated.

/** Every editable field: group => [label, icon, fields => key => [label, type, default, help]] */
function cms_registry() {
    static $r = null;
    if ($r !== null) return $r;
    $r = [
        'school' => ['School details & footer', 'fa-school', [
            'phone'    => ['Phone number', 'text', '09044472688', 'Shown in the footer, contact page and chat assistant'],
            'email'    => ['Email address', 'text', 'info@stbenedicts.edu.ng', ''],
            'address'  => ['Address', 'textarea', 'No 560 A A New G.R.A TRANS-EKULU, ENUGU', ''],
            'hours'    => ['Opening hours (one per line: Day: time)', 'textarea', "Monday: 8:00 AM - 4:00 PM\nTuesday: 8:00 AM - 4:00 PM\nWednesday: 8:00 AM - 4:00 PM\nThursday: 8:00 AM - 4:00 PM\nFriday: 8:00 AM - 2:00 PM\nSaturday: Closed\nSunday: Closed", ''],
            'founded_year' => ['Year founded (used for "years of excellence")', 'text', '2010', ''],
            'motto'    => ['School motto', 'text', 'Christo Duce, Una Sapientia et Virtute Crescimus', ''],
            'motto_translation' => ['Motto translation', 'text', 'With Christ as our guide, together we grow in wisdom and virtue', ''],
            'facebook' => ['Facebook page URL', 'text', '', 'Leave blank to hide the icon'],
            'instagram'=> ['Instagram URL', 'text', '', ''],
            'twitter'  => ['X / Twitter URL', 'text', '', ''],
            'youtube'  => ['YouTube URL', 'text', '', ''],
            'whatsapp' => ['WhatsApp number (international, e.g. 2348012345678)', 'text', '', ''],
        ]],
        'home' => ['Home page', 'fa-home', [
            'welcome_tag'   => ['Welcome label', 'text', "Welcome to St. Benedict's", ''],
            'welcome_title' => ['Welcome heading', 'text', 'Nurturing *Young Minds* with Faith & Excellence', 'Put *asterisks* around words to highlight them'],
            'welcome_text'  => ['Welcome paragraph', 'textarea', "At St. Benedict's Early Years British Academy, we believe that every child is a unique gift from God. Our approach combines the best of the British Early Years Foundation Stage curriculum with strong Christian values, creating an environment where children can flourish academically, socially, and spiritually.", ''],
            'welcome_image' => ['Welcome image', 'image', '', 'Recommended 800x600'],
            'feat1_title' => ['Feature 1 title', 'text', 'British Curriculum', ''],
            'feat1_text'  => ['Feature 1 text', 'text', 'Internationally recognized Early Years Foundation Stage', ''],
            'feat2_title' => ['Feature 2 title', 'text', 'Qualified Teachers', ''],
            'feat2_text'  => ['Feature 2 text', 'text', 'Experienced and caring early years educators', ''],
            'feat3_title' => ['Feature 3 title', 'text', 'Safe Environment', ''],
            'feat3_text'  => ['Feature 3 text', 'text', 'Secure, child-friendly facilities with modern equipment', ''],
            'feat4_title' => ['Feature 4 title', 'text', 'Faith-Based', ''],
            'feat4_text'  => ['Feature 4 text', 'text', 'Christian values integrated into daily learning', ''],
            'mission' => ['Our Mission', 'textarea', "Through Christ's guidance, we build strong minds and kind hearts for the future. As a family of God rooted in love and faith, we cherish every child as God's gift. We learn, play, and grow together in joy, peace, and love.", 'Also shown on the About page'],
            'vision'  => ['Our Vision', 'textarea', 'To nurture children who shine with wisdom, faith, and character, ready to shape a brighter, God-centred future.', ''],
            'goal'    => ['Our Goal', 'textarea', 'To provide every child with a happy, safe, and faith-filled foundation for life, learning, and purpose.', ''],
            'programs_title'    => ['Programs heading', 'text', 'Our Programs', ''],
            'programs_subtitle' => ['Programs sub-heading', 'text', "Age-appropriate learning pathways designed to nurture every child's potential", ''],
            'prog1_title' => ['Program 1 name', 'text', 'Nursery', ''], 'prog1_age' => ['Program 1 ages', 'text', 'Ages 2-3', ''],
            'prog1_text' => ['Program 1 description', 'text', 'Introduction to structured play, social interaction, and early communication skills.', ''],
            'prog2_title' => ['Program 2 name', 'text', 'Reception', ''], 'prog2_age' => ['Program 2 ages', 'text', 'Ages 4-5', ''],
            'prog2_text' => ['Program 2 description', 'text', 'Preparation for formal learning with focus on early literacy, numeracy, and social skills.', ''],
            'prog3_title' => ['Program 3 name', 'text', 'Year 1-2', ''], 'prog3_age' => ['Program 3 ages', 'text', 'Ages 5-7', ''],
            'prog3_text' => ['Program 3 description', 'text', 'Building solid foundations in core subjects following the British curriculum.', ''],
            'prog4_title' => ['Program 4 name', 'text', 'Extra-Curricular', ''], 'prog4_age' => ['Program 4 ages', 'text', 'All Ages', ''],
            'prog4_text' => ['Program 4 description', 'text', 'Enrichment activities to discover and nurture individual talents.', ''],
            'news_title'    => ['News heading', 'text', 'Latest News & Events', 'The posts themselves are managed under News & Events'],
            'news_subtitle' => ['News sub-heading', 'text', 'Keep up with the exciting activities at our school', ''],
            'cta_title' => ['Call-to-action heading', 'text', 'Ready to Give Your Child the Best Start?', ''],
            'cta_text'  => ['Call-to-action text', 'text', "Enroll today at St. Benedict's Early Years British Academy", ''],
        ]],
        'about' => ['About page', 'fa-info-circle', [
            'story_title' => ['Story heading', 'text', 'Our Story', ''],
            'story_lead'  => ['Lead paragraph', 'textarea', "Founded in 2010, ST. BENEDICT'S EARLY YEARS BRITISH ACADEMY has been a beacon of excellence in early childhood education in Enugu.", ''],
            'story_p1'    => ['Paragraph 2', 'textarea', 'What began as a small nursery with just 15 children has grown into one of the most respected British early years institutions in the region. Our journey has been guided by the Benedictine values of prayer, work, and community.', ''],
            'story_p2'    => ['Paragraph 3', 'textarea', 'Today, we serve over 300 children from Nursery through Year 2, providing them with a solid foundation for lifelong learning, grounded in faith and academic excellence.', ''],
            'stat1_value' => ['Highlight 1 number', 'text', '2010', ''], 'stat1_label' => ['Highlight 1 label', 'text', 'Year Founded', ''],
            'stat2_value' => ['Highlight 2 number', 'text', '300+', ''], 'stat2_label' => ['Highlight 2 label', 'text', 'Students', ''],
            'stat3_value' => ['Highlight 3 number', 'text', '25+', ''], 'stat3_label' => ['Highlight 3 label', 'text', 'Teachers', ''],
            'history_image' => ['Story image', 'image', '', ''],
            'facilities_title' => ['Facilities heading', 'text', 'Our Facilities', ''],
            'fac1_title' => ['Facility 1', 'text', 'Modern Classrooms', ''], 'fac1_text' => ['Facility 1 text', 'text', 'Air-conditioned, well-lit classrooms with age-appropriate furniture and learning materials', ''],
            'fac2_title' => ['Facility 2', 'text', 'Computer Lab', ''], 'fac2_text' => ['Facility 2 text', 'text', 'Child-friendly computers with educational software and internet safety measures', ''],
            'fac3_title' => ['Facility 3', 'text', 'Indoor Play Area', ''], 'fac3_text' => ['Facility 3 text', 'text', 'Safe, equipped indoor space for physical activities during inclement weather', ''],
            'fac4_title' => ['Facility 4', 'text', 'Outdoor Playground', ''], 'fac4_text' => ['Facility 4 text', 'text', 'Secure outdoor area with modern play equipment and safety surfaces', ''],
            'fac5_title' => ['Facility 5', 'text', 'School Clinic', ''], 'fac5_text' => ['Facility 5 text', 'text', 'Well-equipped sick bay with qualified nurse on duty', ''],
            'fac6_title' => ['Facility 6', 'text', 'Transportation', ''], 'fac6_text' => ['Facility 6 text', 'text', 'Safe, monitored school buses with trained drivers and attendants', ''],
        ]],
        'academics' => ['Academics page', 'fa-book-open', [
            'overview' => ['Curriculum overview', 'textarea', 'Our curriculum follows the British Early Years Foundation Stage (EYFS) framework, adapted to nurture the whole child - academically, socially, and spiritually. We believe in learning through play, exploration, and guided discovery.', ''],
            'approach' => ['Teaching approach', 'textarea', "We use a child-centered approach where each child's interests and abilities guide their learning journey. Our teachers observe, plan, and assess to ensure every child reaches their full potential.", ''],
            'stage1_age' => ['Stage 1 ages', 'text', 'Ages 2-3', ''], 'stage1_title' => ['Stage 1 name', 'text', 'Nursery', ''],
            'stage1_text' => ['Stage 1 description', 'textarea', 'The Nursery stage focuses on developing independence, social skills, and early communication through structured play and exploration.', ''],
            'stage1_areas' => ['Stage 1 learning areas (one per line)', 'textarea', "Communication and language\nPhysical development\nPersonal, social and emotional development\nEarly literacy and numeracy\nCreative expression", ''],
            'stage2_age' => ['Stage 2 ages', 'text', 'Ages 4-5', ''], 'stage2_title' => ['Stage 2 name', 'text', 'Reception', ''],
            'stage2_text' => ['Stage 2 description', 'textarea', 'Reception builds on Nursery learning with more structured activities preparing children for formal education.', ''],
            'stage2_areas' => ['Stage 2 learning areas (one per line)', 'textarea', "Phonics and early reading\nWriting development\nNumber concepts and problem-solving\nUnderstanding the world\nExpressive arts and design", ''],
            'stage3_age' => ['Stage 3 ages', 'text', 'Ages 5-7', ''], 'stage3_title' => ['Stage 3 name', 'text', 'Year 1 & 2', ''],
            'stage3_text' => ['Stage 3 description', 'textarea', 'Years 1 and 2 introduce more formal learning while maintaining a hands-on, engaging approach.', ''],
            'stage3_areas' => ['Stage 3 learning areas (one per line)', 'textarea', "Reading comprehension\nCreative writing\nMathematics mastery\nScience investigation\nHistory and geography\nComputing skills", ''],
            'quote' => ['Quote text', 'text', 'Play is the highest form of research.', ''],
            'quote_by' => ['Quote author', 'text', '- Albert Einstein', ''],
            'approach_image' => ['Approach image', 'image', '', ''],
            'assessment_note' => ['Assessment note', 'textarea', "Parents receive detailed reports at the end of each term and are invited to discuss their child's progress with teachers.", ''],
        ]],
    ];
    return $r;
}

function cms_default($fullKey) {
    [$g, $k] = array_pad(explode('.', $fullKey, 2), 2, '');
    return cms_registry()[$g][2][$k][2] ?? '';
}

/** All saved values, loaded once per request. Fails soft if the table does not exist yet. */
function cms_all($reload = false) {
    static $vals = null;
    if ($vals === null || $reload) {
        $vals = [];
        try {
            foreach (db()->getRows('SELECT content_key, content_value FROM site_content') as $row) {
                $vals[$row['content_key']] = $row['content_value'];
            }
        } catch (Throwable $e) { /* not migrated yet: defaults apply */ }
    }
    return $vals;
}

function cms($key, $default = null) {
    $all = cms_all();
    if (isset($all[$key]) && $all[$key] !== '') return $all[$key];
    return $default ?? cms_default($key);
}

/** Escaped text. */
function cms_e($key, $default = null) { return e(cms($key, $default)); }

/** Escaped text where *word* becomes a highlighted span. */
function cms_hl($key, $class = 'text-highlight') {
    $t = e(cms($key));
    return preg_replace('/\*([^*]+)\*/', '<span class="' . e($class) . '">$1</span>', $t);
}

/** Image URL for an image field, or $fallback (a path under BASE_URL) when none uploaded. */
function cms_img($key, $fallback = '') {
    $v = cms($key, '');
    if ($v !== '' && basename($v) === $v && is_file(UPLOAD_PATH . 'site/' . $v)) {
        return BASE_URL . '/uploads/site/' . rawurlencode($v);
    }
    return $fallback;
}

/** Lines of a textarea field as an array. */
function cms_lines($key) {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', (string)cms($key))), 'strlen'));
}

// ---- school details used site-wide ------------------------------------------------
function school_phone()   { return cms('school.phone'); }
function school_email()   { return cms('school.email'); }
function school_address() { return cms('school.address'); }
function school_motto()   { return cms('school.motto'); }
function school_hours() {
    $out = [];
    foreach (cms_lines('school.hours') as $line) {
        if (strpos($line, ':') !== false) { [$d, $t] = explode(':', $line, 2); $out[trim($d)] = trim($t); }
    }
    return $out ?: SCHOOL_HOURS;
}

/** Safe mini-markup for custom pages: blank line = paragraph, "## " heading, "- " list, **bold**, [text](url). */
function cms_rich($text) {
    $blocks = preg_split('/\R{2,}/', trim(str_replace("\r", '', (string)$text)));
    $html = '';
    $inline = function ($s) {
        $s = e($s);
        $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);
        $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
            $url = html_entity_decode($m[2], ENT_QUOTES);
            if (!preg_match('~^(https?://|/|mailto:|tel:)~i', $url)) return $m[0];
            if ($url[0] === '/' && strpos($url, '//') !== 0) $url = BASE_URL . $url;
            $ext = preg_match('~^https?://~i', $url) && stripos($url, parse_url(BASE_URL, PHP_URL_HOST) ?: '-') === false;
            return '<a href="' . e($url) . '"' . ($ext ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $m[1] . '</a>';
        }, $s);
        return nl2br($s);
    };
    foreach ($blocks as $b) {
        $b = trim($b);
        if ($b === '') continue;
        if (preg_match('/^(#{1,3})\s+(.+)$/s', $b, $m)) {
            $lvl = strlen($m[1]) + 1;
            $html .= "<h$lvl>" . $inline($m[2]) . "</h$lvl>\n";
        } elseif (preg_match('/^[-*]\s/', $b)) {
            $html .= "<ul>\n";
            foreach (preg_split('/\n/', $b) as $li) $html .= '<li>' . $inline(preg_replace('/^[-*]\s+/', '', $li)) . "</li>\n";
            $html .= "</ul>\n";
        } else {
            $html .= '<p>' . $inline($b) . "</p>\n";
        }
    }
    return $html;
}

// ---- hero slides ---------------------------------------------------------------------
function cms_default_slides() {
    return [
        ['subtitle' => 'Welcome to', 'title' => "ST. BENEDICT'S *EARLY YEARS* BRITISH ACADEMY", 'text' => '', 'image' => '',
         'btn1_label' => 'Apply Now', 'btn1_url' => '/public/apply', 'btn2_label' => 'Schedule a Visit', 'btn2_url' => '/public/contact', 'use_motto' => 1, 'grad' => 0],
        ['subtitle' => 'Quality Education', 'title' => 'British *Early Years* Curriculum', 'text' => 'Nurturing young minds with the best educational practices', 'image' => '',
         'btn1_label' => 'Our Curriculum', 'btn1_url' => '/public/academics', 'btn2_label' => 'Learn More', 'btn2_url' => '/public/about', 'use_motto' => 0, 'grad' => 1],
        ['subtitle' => 'Faith-Based Learning', 'title' => 'Growing in *Wisdom & Virtue*', 'text' => 'Building strong minds and kind hearts for the future', 'image' => '',
         'btn1_label' => 'View Gallery', 'btn1_url' => '/public/gallery', 'btn2_label' => 'Find Us', 'btn2_url' => '/public/contact', 'use_motto' => 0, 'grad' => 2],
    ];
}

function cms_slides() {
    try {
        $rows = db()->getRows('SELECT * FROM hero_slides WHERE is_active = 1 ORDER BY sort_order, id');
        if ($rows) return $rows;
        // table exists but empty: only fall back when the admin has never saved slides
        $n = db()->getRow('SELECT COUNT(*) c FROM hero_slides');
        if ((int)$n['c'] > 0) return [];
    } catch (Throwable $e) { /* not migrated */ }
    return cms_default_slides();
}

/** Link target stored either as a path ("/public/apply") or a full URL. */
function cms_link($url) {
    $url = trim((string)$url);
    if ($url === '' ) return '#';
    if (preg_match('~^(https?://|mailto:|tel:)~i', $url)) return $url;
    if ($url[0] === '/' && strpos($url, '//') !== 0) return BASE_URL . $url;
    return '#';
}

// ---- custom pages ---------------------------------------------------------------------
function cms_menu_pages() {
    static $rows = null;
    if ($rows === null) {
        try { $rows = db()->getRows('SELECT slug, title FROM site_pages WHERE is_published = 1 AND show_in_menu = 1 ORDER BY menu_order, title'); }
        catch (Throwable $e) { $rows = []; }
    }
    return $rows;
}

function cms_slugify($s) {
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
    return $s !== '' ? substr($s, 0, 80) : 'page';
}

/** Validates and stores an uploaded image in uploads/site/. Returns [filename|null, error|null]. */
function cms_store_image($file) {
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];
    $up = Security::validateFileUpload($file, ['jpg', 'jpeg', 'png', 'gif']);
    if (!$up['valid']) return [null, 'Image rejected: ' . $up['message']];
    $dir = UPLOAD_PATH . 'site/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'site_' . bin2hex(random_bytes(10)) . '.' . $up['extension'];
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) return [null, 'Could not store the image'];
    return [$name, null];
}

function cms_remove_image($name) {
    if ($name && basename($name) === $name && is_file(UPLOAD_PATH . 'site/' . $name)) @unlink(UPLOAD_PATH . 'site/' . $name);
}
