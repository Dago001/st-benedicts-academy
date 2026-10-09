<?php
// Accuracy test for the school assistant: question => expected intent (null = must admit it does not know).
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/security.php';
require __DIR__ . '/../includes/chatbot.php';

$cases = [
    // fees
    ['How much is the school fees?', 'fees'], ['what is the tuition for nursery', 'fees'], ['fees pls', 'fees'], ['Do you have installment payment', 'fees'],
    ['How much do I pay for Reception?', 'fees'], ['school feez', 'fees'], ['fee structure', 'fees'], ['how expensive is it', 'fees'],
    // requirements / admission
    ['What documents do I need to apply?', 'requirements'], ['admission requirements', 'requirements'], ['do I need a birth certificate', 'requirements'], ['Is immunization record compulsory?', 'requirements'],
    ['How do I apply?', 'admission'], ['I want to enroll my child', 'admission'], ['what is the admission process', 'admission'], ['how can I register my son', 'admission'], ['is there an entrance assessment', 'admission'],
    ['how old must my child be', 'age'], ['what is the age limit', 'age'], ['Can a 2 year old join? what age do you accept', 'age'],
    // programmes / curriculum
    ['What classes do you offer?', 'programs'], ['tell me about nursery', 'programs'], ['do you have reception', 'programs'], ['what programmes are available', 'programs'],
    ['what curriculum do you use', 'curriculum'], ['Do you follow the british curriculum', 'curriculum'], ['what subjects do you teach', 'curriculum'], ['how do you track progress and assess children', 'curriculum'],
    // contact etc
    ['What is your phone number', 'contact'], ['email address please', 'contact'], ['how can I contact the school', 'contact'],
    ['Where is the school located?', 'location'], ['what is your address', 'location'], ['where are you', 'location'],
    ['What time do you open?', 'hours'], ['opening hours', 'hours'], ['are you open on saturday', 'hours'], ['when do you close', 'hours'],
    ['can I book a visit', 'visit'], ['I would like to tour the school', 'visit'], ['schedule a visit', 'visit'],
    ['how do I log in to the parent portal', 'portal'], ['where can I check my childs results', 'portal'],
    ['I forgot my password', 'password'], ['my account is locked', 'password'],
    ['what facilities do you have', 'facilities'], ['do you have a playground', 'facilities'], ['is there a school clinic', 'facilities'],
    ['do you have school bus', 'transport'], ['is there transport', 'transport'],
    ['is the school safe', 'safety'], ['child safety and security', 'safety'],
    ['are you a christian school', 'faith'], ['what is your motto', 'faith'],
    ['tell me about the school', 'about'], ['when was the school founded', 'about'], ['what is your mission', 'about'],
    ['any upcoming events', 'news'], ['latest news', 'news'],
    ['when does next term start', 'term'], ['school calendar', 'term'],
    ['what about uniform', 'uniform'], ['do you offer scholarships', 'uniform'], ['is lunch provided', 'uniform'],
    ['I want to speak to a real person', 'human'], ['I have a complaint', 'human'],
    ['hello', 'greeting'], ['good morning', 'greeting'], ['thank you so much', 'thanks'], ['bye', 'bye'],
    // must NOT be answered with invented facts
    ['what is the meaning of life', null], ['who won the football match yesterday', null], ['asdfgh qwerty', null], ['can you do my homework', null],
];
$ok = 0; $bad = [];
foreach ($cases as [$q, $want]) {
    $r = SchoolBot::answer($q, []);
    $got = $r['intent'] ?? null;
    if ($got === $want) $ok++; else $bad[] = sprintf("%-55s want=%-12s got=%s", $q, $want ?? 'unknown', $got ?? 'unknown');
}
printf("%d/%d intents correct\n", $ok, count($cases));
foreach ($bad as $b) echo "  MISS: $b\n";

// Content checks: facts must come from real data / site copy
$fees = SchoolBot::answer('how much is nursery fees', []);
$txt = json_encode($fees, JSON_UNESCAPED_UNICODE);
$fail = 0;
$check = function ($name, $cond) use (&$fail) { if (!$cond) { $fail++; echo "  FAIL: $name\n"; } };
$check('fees mention a naira amount from the database', strpos($txt, '₦') !== false && strpos($txt, '225,000') !== false);
$check('fees answer includes a confirm-with-office note', stripos($txt, 'confirm') !== false);
$r = SchoolBot::answer('what is your phone number', []); $check('contact shows school phone', strpos(json_encode($r), SCHOOL_PHONE) !== false);
$r = SchoolBot::answer('where is the school', []); $check('location shows address', strpos(json_encode($r, JSON_UNESCAPED_UNICODE), 'ENUGU') !== false);
$r = SchoolBot::answer('and for reception?', ['last_intent' => 'fees']); $check('follow-up keeps the fees topic', ($r['intent'] ?? '') === 'fees');
$r = SchoolBot::answer('status APP-2026-9999', []); $check('status without email asks for it', stripos(json_encode($r), 'email') !== false);
$r = SchoolBot::answer('status APP-2026-9999 nobody@example.com', []); $check('wrong app number reveals nothing', stripos(json_encode($r), "couldn't find") !== false);
$r = SchoolBot::answer('what is the term start date', []); $check('unpublished dates are not invented', stripos(json_encode($r), 'would not want to give you the wrong') !== false);
$r = SchoolBot::answer('what is the bus fare', []); $check('no invented transport prices', strpos(json_encode($r, JSON_UNESCAPED_UNICODE), '₦') === false);
$check('redact removes email and phone', SchoolBot::redact('mail me a@b.com or 08031234567') === 'mail me [email] or [number]');
echo $fail ? "$fail content checks failed\n" : "content checks passed\n";
exit(($bad || $fail) ? 1 : 0);
