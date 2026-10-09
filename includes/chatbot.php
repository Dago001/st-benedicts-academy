<?php
// includes/chatbot.php - rule-based school assistant.
//
// Design goals: accuracy over cleverness. Every factual answer comes either from
// the database (fees, classes, news, application status) or from the school's own
// published pages (programmes, requirements, contact details). Anything the school
// has not published is NOT guessed: the bot says so and points to the office.

class SchoolBot
{
    /** Intent definitions: keyword => weight, phrase => weight. */
    private static function intents()
    {
        return [
            'greeting' => ['k' => ['hello' => 3, 'hi' => 3, 'hey' => 3, 'greetings' => 3, 'morning' => 2, 'afternoon' => 2, 'evening' => 2], 'p' => ['good morning' => 4, 'good afternoon' => 4, 'good evening' => 4]],
            'thanks'   => ['k' => ['thanks' => 4, 'thank' => 4, 'thankyou' => 4, 'appreciate' => 3, 'cheers' => 3], 'p' => ['thank you' => 5]],
            'bye'      => ['k' => ['bye' => 4, 'goodbye' => 4, 'later' => 1], 'p' => ['see you' => 4, 'good night' => 4]],
            'fees'     => ['k' => ['fee' => 4, 'fees' => 4, 'tuition' => 4, 'cost' => 3, 'price' => 3, 'pay' => 2, 'payment' => 3, 'levy' => 3, 'charge' => 2, 'expensive' => 3, 'much' => 1, 'afford' => 2, 'bursar' => 3, 'installment' => 3, 'instalment' => 3],
                           'p' => ['how much' => 4, 'school fees' => 5, 'fee structure' => 5]],
            'requirements' => ['k' => ['requirement' => 4, 'requirements' => 4, 'document' => 4, 'documents' => 4, 'certificate' => 3, 'passport' => 3, 'photograph' => 3, 'immunization' => 4, 'immunisation' => 4, 'vaccination' => 3, 'need' => 1, 'bring' => 2, 'upload' => 2],
                           'p' => ['what do i need' => 4, 'admission requirements' => 6, 'birth certificate' => 5]],
            'admission' => ['k' => ['admission' => 4, 'admissions' => 4, 'apply' => 4, 'application' => 4, 'enrol' => 4, 'enroll' => 4, 'enrolment' => 4, 'enrollment' => 4, 'register' => 3, 'registration' => 3, 'join' => 2, 'process' => 2, 'admit' => 3, 'form' => 1, 'assessment' => 3, 'interview' => 3, 'deadline' => 2, 'vacancy' => 3, 'space' => 1, 'place' => 1],
                           'p' => ['how to apply' => 6, 'how do i apply' => 6, 'admission process' => 6, 'get admission' => 5, 'new student' => 3, 'new pupil' => 3]],
            'age'      => ['k' => ['age' => 3, 'old' => 3, 'years' => 1, 'eligible' => 3, 'eligibility' => 3, 'minimum' => 2, 'maximum' => 2, 'toddler' => 2], 'p' => ['how old' => 5, 'age limit' => 5, 'what age' => 5, 'age range' => 5]],
            'programs' => ['k' => ['program' => 4, 'programs' => 4, 'programme' => 4, 'programmes' => 4, 'class' => 3, 'classes' => 3, 'nursery' => 3, 'reception' => 3, 'year' => 2, 'level' => 2, 'grade' => 2, 'offer' => 2, 'stage' => 2, 'primary' => 2, 'creche' => 2, 'kindergarten' => 3], 'p' => ['year 1' => 4, 'year 2' => 4, 'which classes' => 5]],
            'curriculum' => ['k' => ['curriculum' => 5, 'syllabus' => 4, 'eyfs' => 5, 'british' => 3, 'subject' => 3, 'subjects' => 3, 'teach' => 3, 'taught' => 3, 'learn' => 2, 'learning' => 2, 'phonics' => 3, 'assessment' => 1, 'assess' => 3, 'progress' => 2, 'report' => 2, 'play' => 2, 'academics' => 4, 'academic' => 3], 'p' => ['what do you teach' => 6, 'how do you teach' => 5]],
            'contact'  => ['k' => ['contact' => 4, 'phone' => 4, 'call' => 3, 'email' => 4, 'mail' => 2, 'number' => 3, 'reach' => 3, 'whatsapp' => 3, 'talk' => 2, 'speak' => 2, 'office' => 2, 'telephone' => 4], 'p' => ['get in touch' => 5, 'phone number' => 6, 'email address' => 6]],
            'location' => ['k' => ['where' => 3, 'location' => 4, 'address' => 4, 'located' => 4, 'directions' => 4, 'map' => 3, 'enugu' => 3, 'situated' => 3, 'find' => 1, 'ekulu' => 3, 'gra' => 2], 'p' => ['where are you' => 6, 'where is the school' => 6, 'how do i get' => 4]],
            'hours'    => ['k' => ['hours' => 4, 'open' => 3, 'opening' => 4, 'close' => 2, 'closing' => 3, 'time' => 2, 'times' => 2, 'resume' => 3, 'weekend' => 2, 'saturday' => 2, 'sunday' => 2, 'monday' => 1, 'friday' => 1, 'schedule' => 2, 'timetable' => 2], 'p' => ['what time' => 5, 'opening hours' => 6, 'office hours' => 6, 'when do you open' => 6, 'when do you close' => 6]],
            'visit'    => ['k' => ['visit' => 4, 'tour' => 4, 'inspect' => 3, 'appointment' => 4, 'see' => 1, 'schedule' => 1, 'open day' => 3], 'p' => ['schedule a visit' => 7, 'book a visit' => 7, 'come and see' => 5, 'take a tour' => 6, 'open day' => 5]],
            'portal'   => ['k' => ['portal' => 5, 'login' => 5, 'log' => 1, 'signin' => 5, 'account' => 3, 'username' => 3, 'result' => 3, 'results' => 3, 'grades' => 2, 'attendance' => 2, 'dashboard' => 3, 'homework' => 2, 'assignment' => 2, 'assignments' => 2], 'p' => ['log in' => 5, 'sign in' => 5, 'parent portal' => 7, 'check results' => 5, 'view results' => 5]],
            'password' => ['k' => ['password' => 5, 'forgot' => 4, 'forgotten' => 4, 'reset' => 4, 'locked' => 4, 'lockout' => 4, 'unlock' => 4, 'cant' => 1], 'p' => ['forgot password' => 7, 'reset password' => 7, 'cannot log in' => 5, 'cant log in' => 5, 'account locked' => 6]],
            'facilities' => ['k' => ['facility' => 4, 'facilities' => 4, 'playground' => 4, 'lab' => 3, 'computer' => 3, 'library' => 3, 'classroom' => 3, 'clinic' => 4, 'nurse' => 3, 'sickbay' => 3, 'air' => 1, 'conditioned' => 2, 'indoor' => 2, 'outdoor' => 2, 'equipment' => 2], 'p' => ['air conditioned' => 4]],
            'transport' => ['k' => ['transport' => 5, 'bus' => 5, 'buses' => 5, 'pickup' => 4, 'drop' => 2, 'driver' => 3, 'commute' => 3], 'p' => ['school bus' => 6, 'pick up' => 5, 'drop off' => 5]],
            'safety'   => ['k' => ['safe' => 3, 'safety' => 4, 'security' => 4, 'secure' => 3, 'health' => 3, 'medical' => 3, 'sick' => 3, 'allergy' => 3, 'allergies' => 3, 'protect' => 2, 'safeguarding' => 4], 'p' => ['child safety' => 5]],
            'faith'    => ['k' => ['christian' => 4, 'faith' => 4, 'religion' => 4, 'religious' => 4, 'catholic' => 4, 'church' => 3, 'prayer' => 3, 'god' => 2, 'benedictine' => 4, 'values' => 2, 'christ' => 3, 'motto' => 4, 'mass' => 2], 'p' => ['christian values' => 6]],
            'about'    => ['k' => ['about' => 3, 'history' => 4, 'founded' => 4, 'established' => 4, 'story' => 3, 'mission' => 4, 'vision' => 4, 'goal' => 3, 'who' => 2, 'school' => 1, 'academy' => 1, 'leadership' => 3, 'principal' => 3, 'head' => 2, 'headteacher' => 4, 'staff' => 2, 'teachers' => 2, 'qualified' => 2], 'p' => ['tell me about' => 4, 'about the school' => 6, 'who are you' => 6, 'your mission' => 6, 'your vision' => 6]],
            'news'     => ['k' => ['news' => 5, 'event' => 4, 'events' => 4, 'upcoming' => 4, 'announcement' => 4, 'announcements' => 4, 'happening' => 3, 'activities' => 3, 'celebration' => 2, 'sports' => 2, 'update' => 2], 'p' => ['whats happening' => 5, 'latest news' => 6, 'upcoming events' => 7]],
            'term'     => ['k' => ['term' => 3, 'calendar' => 4, 'holiday' => 4, 'holidays' => 4, 'break' => 3, 'vacation' => 3, 'session' => 3, 'resumption' => 4, 'semester' => 3, 'dates' => 3], 'p' => ['term dates' => 7, 'school calendar' => 7, 'next term' => 5, 'when does school' => 5]],
            'uniform'  => ['k' => ['uniform' => 5, 'uniforms' => 5, 'textbook' => 4, 'textbooks' => 4, 'books' => 3, 'stationery' => 3, 'wear' => 2, 'dress' => 3, 'shoes' => 2, 'lunch' => 3, 'meals' => 3, 'feeding' => 3, 'food' => 3, 'snack' => 3, 'scholarship' => 5, 'discount' => 4, 'sibling' => 4, 'bursary' => 5], 'p' => []],
            'status'   => ['k' => ['status' => 5, 'track' => 3, 'progress' => 1, 'accepted' => 3, 'rejected' => 3, 'outcome' => 3, 'decision' => 3, 'submitted' => 2], 'p' => ['application status' => 8, 'check my application' => 8, 'track my application' => 8, 'was i accepted' => 6]],
            'human'    => ['k' => ['human' => 5, 'person' => 4, 'agent' => 4, 'representative' => 5, 'someone' => 3, 'staff' => 1, 'complaint' => 4, 'complain' => 4, 'urgent' => 4, 'emergency' => 5, 'principal' => 2], 'p' => ['speak to someone' => 8, 'talk to a person' => 8, 'real person' => 7, 'speak to a human' => 8, 'speak with' => 4]],
        ];
    }

    private static function normalize($text)
    {
        $t = mb_strtolower($text, 'UTF-8');
        $t = str_replace(["'", '’', '`'], '', $t);
        $t = preg_replace('/[^\p{L}\p{N}@.\-\/ ]+/u', ' ', $t);
        $t = preg_replace('/\s+/', ' ', trim($t));
        return $t;
    }

    private static function stem($w)
    {
        if (mb_strlen($w) > 4 && substr($w, -3) === 'ies') return substr($w, 0, -3) . 'y';
        if (mb_strlen($w) > 3 && substr($w, -1) === 's' && substr($w, -2) !== 'ss') return substr($w, 0, -1);
        return $w;
    }

    /** Score every intent for a message; returns [intent => score] sorted high to low. */
    public static function score($message)
    {
        $norm = ' ' . self::normalize($message) . ' ';
        $words = array_values(array_filter(explode(' ', trim($norm))));
        $stems = array_map([self::class, 'stem'], $words);
        $scores = [];
        foreach (self::intents() as $name => $def) {
            $s = 0;
            foreach ($def['p'] as $phrase => $w) {
                if (strpos($norm, ' ' . $phrase . ' ') !== false || strpos($norm, ' ' . $phrase) !== false) $s += $w;
            }
            foreach ($def['k'] as $kw => $w) {
                $kwStem = self::stem($kw);
                $hit = false;
                foreach ($words as $i => $word) {
                    if ($word === $kw || $stems[$i] === $kwStem) { $hit = true; break; }
                    // typo tolerance only for longer words so "fee"/"free" are not confused
                    if (mb_strlen($kw) >= 4 && mb_strlen($word) >= 4 && $word[0] === $kw[0] && abs(mb_strlen($kw) - mb_strlen($word)) <= 1 && levenshtein($word, $kw) <= 1) { $hit = true; break; }
                }
                if ($hit) $s += $w;
            }
            if ($name === 'status' && !preg_match('/\b(application|applied|admission|status|app-?\d)/', $norm)) $s = 0;
            if ($s > 0) $scores[$name] = $s;
        }
        arsort($scores);
        return $scores;
    }

    // ---------------------------------------------------------------------

    private static function classFilter($norm)
    {
        $map = ['nursery' => 'Nursery', 'reception' => 'Reception', 'year 1' => 'Year 1', 'year one' => 'Year 1', 'year 2' => 'Year 2', 'year two' => 'Year 2', 'primary 1' => 'Year 1', 'primary 2' => 'Year 2'];
        foreach ($map as $needle => $name) {
            if (strpos($norm, $needle) !== false) return $name;
        }
        return null;
    }

    private static function money($v) { return '₦' . number_format((float)$v, 0); }

    private static function r(array $reply, array $links = [], array $suggest = [])
    {
        return ['reply' => $reply, 'links' => $links, 'suggestions' => $suggest];
    }

    private static function link($label, $path) { return ['label' => $label, 'url' => BASE_URL . $path]; }

    /** Main entry point. $ctx['last_intent'] enables short follow-up questions. */
    public static function answer($message, array $ctx = [])
    {
        $message = trim($message);
        $norm = self::normalize($message);
        $scores = self::score($message);
        $top = $scores ? array_key_first($scores) : null;
        $topScore = $top ? $scores[$top] : 0;

        // Application status lookup (needs the number AND the email used to apply)
        if (preg_match('/\bAPP-?\d{4}-?\d{3,}\b/i', $message, $m) || $top === 'status') {
            return ['intent' => 'status'] + self::applicationStatus($message);
        }

        // Follow-up such as "and reception?" after a fees/programmes answer
        if (!empty($ctx['last_intent']) && self::classFilter($norm) && count(explode(' ', $norm)) <= 5 && in_array($ctx['last_intent'], ['fees', 'programs', 'age'], true)) {
            $top = $ctx['last_intent'];
            $topScore = 3;
        }

        if ($top === 'about' && self::classFilter($norm)) { $top = 'programs'; }

        if (!$top || $topScore < 3) {
            return ['intent' => null, 'matched' => false] + self::r(
                ["I'm sorry, I don't have a reliable answer to that. I'd rather not guess, so please contact the school office and they will help you directly."],
                [self::link('Contact the school', '/public/contact.php'), ['label' => 'Call ' . SCHOOL_PHONE, 'url' => 'tel:' . SCHOOL_PHONE]],
                ['Admission requirements', 'School fees', 'Our programmes', 'Contact details']
            );
        }

        $out = ['intent' => $top, 'matched' => true] + self::respond($top, $norm);
        // Gently offer the runner-up topic when the question was ambiguous
        $second = array_keys($scores)[1] ?? null;
        if ($second && $scores[$second] >= $topScore * 0.75 && !in_array($second, ['greeting', 'thanks', 'bye'], true)) {
            $labels = ['fees' => 'School fees', 'admission' => 'How to apply', 'requirements' => 'Admission requirements', 'programs' => 'Our programmes', 'contact' => 'Contact details', 'hours' => 'Opening hours', 'location' => 'Where is the school?', 'visit' => 'Schedule a visit', 'portal' => 'Parent portal help', 'curriculum' => 'Curriculum', 'age' => 'Age requirements', 'news' => 'Latest news'];
            if (isset($labels[$second])) array_unshift($out['suggestions'], $labels[$second]);
            $out['suggestions'] = array_values(array_unique($out['suggestions']));
        }
        return $out;
    }

    private static function respond($intent, $norm)
    {
        $db = db();
        switch ($intent) {
            case 'greeting':
                return self::r(['Hello! 👋 Welcome to ' . SCHOOL_NAME . '. I can help with admissions, fees, programmes, opening hours and contact details. What would you like to know?'], [], ['How to apply', 'School fees', 'Our programmes', 'Contact details']);

            case 'thanks':
                return self::r(["You're welcome! Is there anything else I can help you with?"], [], ['How to apply', 'Contact details']);

            case 'bye':
                return self::r(['Goodbye, and thank you for your interest in ' . SCHOOL_NAME . '. God bless!']);

            case 'fees':
                return self::fees($norm);

            case 'requirements':
                return self::r(
                    ['To apply you will need:'],
                    [self::link('Start the online application', '/public/apply.php'), self::link('Admission page', '/public/admissions.php')],
                    ['How to apply', 'School fees', 'Age requirements']
                ) + ['list' => ['Birth certificate (required)', 'Passport photographs (required)', 'Immunisation record (optional)', 'Previous school report, if your child has attended school (optional)', 'A completed application form and the non-refundable application fee'],
                     'note' => 'Documents can be uploaded online as PDF, JPG or PNG (max 5MB each). Please ask the office for the current application fee amount.'];

            case 'admission':
                return self::r(['Admission is simple:'],
                    [self::link('Apply online', '/public/apply.php'), self::link('Admission details', '/public/admissions.php')],
                    ['Admission requirements', 'School fees', 'Schedule a visit'])
                    + ['list' => ['Submit the online application', 'Pay the application fee', 'Schedule an assessment or visit', 'Child assessment (for Year 1-2)', 'Receive the admission decision', 'Complete acceptance and enrolment'],
                       'note' => 'After applying, our admissions team contacts you within 3-5 working days. You can check progress any time: send me your application number and the email you used.'];

            case 'age':
                return self::r(['We admit children from age 2 to 7: Nursery (ages 2-3), Reception (ages 4-5) and Year 1-2 (ages 5-7).'],
                    [self::link('Online application', '/public/apply.php')], ['Our programmes', 'Admission requirements']);

            case 'programs':
                $filter = self::classFilter($norm);
                $all = [
                    'Nursery' => 'Ages 2-3: structured play, social interaction and early communication skills.',
                    'Reception' => 'Ages 4-5: early literacy, numeracy and social skills in preparation for formal learning.',
                    'Year 1-2' => 'Ages 5-7: core subjects following the British curriculum, in a hands-on, engaging way.',
                ];
                $list = [];
                foreach ($all as $k => $v) {
                    if (!$filter || stripos($k, substr($filter, 0, 4)) !== false) $list[] = "$k - $v";
                }
                $rows = $db->getRows('SELECT DISTINCT class_name FROM classes WHERE is_active = 1 ORDER BY class_name');
                $note = $rows ? 'Currently open classes: ' . implode(', ', array_column($rows, 'class_name')) . '.' : '';
                return self::r(['We follow the British Early Years Foundation Stage (EYFS):'], [self::link('Academics', '/public/academics.php')], ['School fees', 'Age requirements', 'How to apply'])
                    + ['list' => $list, 'note' => $note];

            case 'curriculum':
                return self::r(['We follow the British Early Years Foundation Stage curriculum, with Christian values woven throughout. Children learn through play and discovery: child-initiated play, adult-guided activities and regular outdoor learning.',
                    'Progress is tracked through observation, learning journeys (digital portfolios) and termly assessments. Parents receive a detailed report each term and are invited to discuss progress with teachers.'],
                    [self::link('Academics', '/public/academics.php')], ['Our programmes', 'Parent portal help']);

            case 'contact':
                return self::r(['You can reach the school office here:'],
                    [['label' => 'Call ' . SCHOOL_PHONE, 'url' => 'tel:' . SCHOOL_PHONE], ['label' => 'Email us', 'url' => 'mailto:' . SCHOOL_EMAIL], self::link('Contact form', '/public/contact.php')],
                    ['Opening hours', 'Where is the school?'])
                    + ['list' => ['Phone: ' . SCHOOL_PHONE, 'Email: ' . SCHOOL_EMAIL, 'Address: ' . SCHOOL_ADDRESS]];

            case 'location':
                return self::r(['We are located at ' . SCHOOL_ADDRESS . '.'],
                    [['label' => 'Open in Maps', 'url' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(SCHOOL_ADDRESS)], self::link('Contact page', '/public/contact.php')],
                    ['Opening hours', 'Schedule a visit']);

            case 'hours':
                $list = [];
                foreach (SCHOOL_HOURS as $day => $h) $list[] = "$day: $h";
                $today = SCHOOL_HOURS[date('l')] ?? '';
                return self::r(['Our office hours are:'], [], ['Schedule a visit', 'Contact details'])
                    + ['list' => $list, 'note' => 'Today (' . date('l') . '): ' . $today . '. Times are West Africa Time.'];

            case 'visit':
                return self::r(['We would love to show you around. Please contact the office to book a visit or an assessment, and mention the class you are interested in.'],
                    [self::link('Request a visit', '/public/contact.php'), ['label' => 'Call ' . SCHOOL_PHONE, 'url' => 'tel:' . SCHOOL_PHONE]], ['Opening hours', 'Where is the school?']);

            case 'portal':
                return self::r(['Parents, students and teachers sign in to the portal with the email address and password given by the school. There you can see results, attendance, fees, assignments and messages.',
                    'If you do not have login details yet, please contact the school office.'],
                    [self::link('Go to login', '/login.php'), self::link('Contact the office', '/public/contact.php')], ['Forgot password', 'Contact details']);

            case 'password':
                return self::r(['You can reset your password yourself: choose "Forgot password" on the login page and follow the emailed link (valid for one hour). After 5 wrong attempts an account is locked for 15 minutes.',
                    'If you are still unable to sign in, the school office can help.'],
                    [self::link('Reset password', '/forgot-password.php'), self::link('Contact the office', '/public/contact.php')], ['Parent portal help']);

            case 'facilities':
                return self::r(['Our facilities include:'], [self::link('About us', '/public/about.php')], ['Transport', 'Child safety'])
                    + ['list' => ['Modern, air-conditioned classrooms', 'A computer lab with child-friendly educational software', 'An indoor play area', 'A secure outdoor playground', 'A school clinic with a qualified nurse on duty']];

            case 'transport':
                return self::r(['We run safe, monitored school buses with trained drivers and attendants. For routes, pick-up points and charges, please ask the school office, as I do not have those details.'],
                    [self::link('Contact the office', '/public/contact.php')], ['School fees', 'Contact details']);

            case 'safety':
                return self::r(['Child safety is a priority: a secure outdoor play area with safety surfaces, monitored school transport, and a school clinic with a qualified nurse on duty. Please tell the school about any allergies or medical needs when you apply.'],
                    [self::link('About us', '/public/about.php')], ['Facilities', 'Transport']);

            case 'faith':
                return self::r(['We are a faith-based school guided by the Benedictine values of prayer, work and community. Our motto is "' . SCHOOL_MOTTO . '" (With Christ as our guide, together we grow in wisdom and virtue). Christian values are integrated into daily learning.'],
                    [self::link('About us', '/public/about.php')], ['Curriculum', 'How to apply']);

            case 'about':
                return self::r([SCHOOL_NAME . ' was founded in 2010 in Enugu. It began as a small nursery and now teaches children from Nursery to Year 2 using the British Early Years curriculum, grounded in faith and academic excellence.'],
                    [self::link('Our story', '/public/about.php'), self::link('Academics', '/public/academics.php')], ['Our programmes', 'Facilities', 'How to apply']);

            case 'news':
                $rows = $db->getRows("SELECT id, title, type, event_date, created_at FROM news_events WHERE is_published = 1 ORDER BY (type = 'event' AND event_date >= CURDATE()) DESC, COALESCE(event_date, created_at) DESC LIMIT 4");
                if (!$rows) {
                    return self::r(['There are no news items published right now. Please check back soon.'], [self::link('News & events', '/public/news.php')]);
                }
                $list = [];
                foreach ($rows as $r) {
                    $d = $r['type'] === 'event' && $r['event_date'] ? date('j M Y', strtotime($r['event_date'])) : date('j M Y', strtotime($r['created_at']));
                    $list[] = ucfirst($r['type']) . ' (' . $d . '): ' . $r['title'];
                }
                return self::r(['Here is the latest from the school:'], [self::link('All news & events', '/public/news.php')], ['School fees', 'Schedule a visit']) + ['list' => $list];

            case 'term':
                return self::r(['I do not have the term dates published here, and I would not want to give you the wrong ones. The office can confirm the current calendar, resumption dates and holidays.'],
                    [self::link('Contact the office', '/public/contact.php'), ['label' => 'Call ' . SCHOOL_PHONE, 'url' => 'tel:' . SCHOOL_PHONE]], ['Opening hours']);

            case 'uniform':
                return self::r(['Details about uniforms, books, meals and any discounts or scholarships are not published online, so I cannot answer accurately. Please ask the school office.'],
                    [self::link('Contact the office', '/public/contact.php'), ['label' => 'Call ' . SCHOOL_PHONE, 'url' => 'tel:' . SCHOOL_PHONE]], ['School fees']);

            case 'human':
                return self::r(['Of course. The school office will be glad to help you personally:'],
                    [['label' => 'Call ' . SCHOOL_PHONE, 'url' => 'tel:' . SCHOOL_PHONE], ['label' => 'Email us', 'url' => 'mailto:' . SCHOOL_EMAIL], self::link('Contact form', '/public/contact.php')], ['Opening hours']);
        }
        return self::r(['How can I help?'], [], ['School fees', 'How to apply']);
    }

    /** Fees come straight from the fee_structure table (latest academic year). */
    private static function fees($norm)
    {
        $db = db();
        $filter = self::classFilter($norm);
        $year = currentAcademicYear();
        $sql = "SELECT c.class_name, c.section, f.term, f.fee_type, f.amount FROM fee_structure f JOIN classes c ON f.class_id = c.id
                WHERE f.academic_year = ? AND c.is_active = 1";
        $params = [$year];
        if ($filter) { $sql .= ' AND c.class_name LIKE ?'; $params[] = $filter . '%'; }
        $rows = $db->getRows($sql . ' ORDER BY c.class_name, c.section, f.term, f.fee_type', $params);

        $links = [self::link('Admissions & fees', '/public/admissions.php'), self::link('Contact the bursar', '/public/contact.php')];
        $suggest = ['How to apply', 'Admission requirements', 'Contact details'];
        if (!$rows) {
            return self::r([($filter ? "I don't have a published fee schedule for $filter " : "I don't have a published fee schedule ") . "for the $year session. Please contact the school office for the current fees. The application fee amount is also confirmed by the office."], $links, $suggest);
        }
        $grouped = [];
        foreach ($rows as $r) {
            $key = trim($r['class_name'] . ' ' . $r['section']) . ' - ' . ($r['term'] ?: 'All terms');
            $grouped[$key]['items'][] = $r['fee_type'] . ' ' . self::money($r['amount']);
            $grouped[$key]['total'] = ($grouped[$key]['total'] ?? 0) + $r['amount'];
        }
        $list = [];
        foreach (array_slice($grouped, 0, 8, true) as $k => $g) {
            $list[] = "$k: " . self::money($g['total']) . ' (' . implode(', ', $g['items']) . ')';
        }
        $more = count($grouped) > 8 ? ' Showing the first 8 of ' . count($grouped) . ' - ask the office for the full list.' : '';
        return self::r(["Fees for the $year session" . ($filter ? " ($filter)" : '') . ':'], $links, $suggest)
            + ['list' => $list, 'note' => 'Fees can change and may exclude the application fee, uniforms and books. Please confirm the final amount with the school office.' . $more];
    }

    /** Looks up a submitted application. Requires BOTH the number and the applicant's email. */
    private static function applicationStatus($message)
    {
        $num = null; $email = null;
        if (preg_match('/\bAPP-?(\d{4})-?(\d{3,})\b/i', $message, $m)) $num = 'APP-' . $m[1] . '-' . $m[2];
        if (preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $message, $m)) $email = mb_strtolower($m[0]);
        if (!$num || !$email) {
            return self::r(['I can check your application status. Please send both your application number (like APP-2026-1234) and the email address you applied with, in one message. This keeps your details private.'],
                [self::link('Apply online', '/public/apply.php')], ['How to apply']) + ['matched' => true];
        }
        $db = db();
        $row = $db->getRow('SELECT status, created_at AS at FROM applications WHERE application_number = ? AND LOWER(parent_email) = ?', [$num, $email])
            ?: $db->getRow('SELECT status, submitted_at AS at FROM admissions WHERE application_number = ? AND LOWER(parent_email) = ?', [$num, $email]);
        if (!$row) {
            return self::r(["I couldn't find an application matching that number and email. Please check both and try again, or contact the admissions office."],
                [self::link('Contact the office', '/public/contact.php')], ['Contact details']) + ['matched' => true];
        }
        $text = ['pending' => 'received and waiting to be reviewed', 'reviewing' => 'being reviewed by the admissions team', 'accepted' => 'ACCEPTED. Congratulations! The office will contact you with enrolment instructions', 'rejected' => 'not successful this time. Please contact the admissions office to discuss next steps'];
        return self::r(["Your application $num (submitted " . date('j M Y', strtotime($row['at'])) . ') is ' . ($text[$row['status']] ?? $row['status']) . '.'],
            [self::link('Contact admissions', '/public/contact.php')], ['Admission requirements']) + ['matched' => true];
    }

    /** Remove emails and phone numbers before a question is logged. */
    public static function redact($text)
    {
        $text = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $text);
        $text = preg_replace('/\+?\d[\d\s\-]{7,}\d/', '[number]', $text);
        $text = preg_replace('/\bAPP-?\d{4}-?\d{3,}\b/i', '[app-no]', $text);
        return mb_substr($text, 0, 300);
    }
}
