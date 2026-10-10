<?php
// includes/locations.php - Nigerian states / LGAs plus a "live outside Nigeria" option, shared by every form that asks for a location.
// Data: assets/data/ng-locations.json (37 states incl. FCT, 774 LGAs). JS enhancement: assets/js/location-picker.js

const LOC_OTHER = '__other';

function ng_locations() {
    static $d = null;
    if ($d === null) {
        $d = json_decode((string)@file_get_contents(dirname(__DIR__) . '/assets/data/ng-locations.json'), true) ?: [];
    }
    return $d;
}

/**
 * Resolve the posted location fields into [country, state, lga] or an error message.
 * Posted: state (a Nigerian state name or "__other"), lga (an LGA name or "__other"),
 *         country_other / state_other / area_other (outside Nigeria) or lga_other (LGA not in the list), all free text.
 */
function location_resolve(array $src, $required = true) {
    $t = function ($k, $max = 100) use ($src) {
        return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($src[$k] ?? ''))), 0, $max);
    };
    $state = $t('state'); $lga = $t('lga');
    $all = ng_locations();

    if ($state === LOC_OTHER) {
        $country = $t('country_other'); $region = $t('state_other'); $area = $t('area_other');
        if ($country === '' || $region === '') return ['ok' => false, 'error' => 'Please enter your country and state/province/region'];
        if (strcasecmp($country, 'Nigeria') === 0) return ['ok' => false, 'error' => 'Nigeria is in the state list - please choose your state instead of "Other"'];
        return ['ok' => true, 'country' => $country, 'state' => $region, 'lga' => $area];
    }
    if ($state === '') {
        return $required ? ['ok' => false, 'error' => 'Please select your state'] : ['ok' => true, 'country' => '', 'state' => '', 'lga' => ''];
    }
    if (!isset($all[$state])) return ['ok' => false, 'error' => 'Please select a valid state'];
    if ($lga === LOC_OTHER) {
        $lga = $t('lga_other');
        if ($lga === '') return ['ok' => false, 'error' => 'Please type your local government area'];
    } elseif ($lga === '') {
        if ($required) return ['ok' => false, 'error' => 'Please select your local government area'];
    } elseif (!in_array($lga, $all[$state], true)) {
        return ['ok' => false, 'error' => 'Please select a valid local government area'];
    }
    return ['ok' => true, 'country' => 'Nigeria', 'state' => $state, 'lga' => $lga];
}

/** One-line description for emails/admin views. */
function location_label($country, $state, $lga = '') {
    $parts = array_filter([trim((string)$lga), trim((string)$state), ($country !== null && strcasecmp(trim((string)$country), 'Nigeria') !== 0) ? trim((string)$country) : ''], 'strlen');
    return implode(', ', $parts);
}

/**
 * Prints the state / LGA / "other country" fields.
 * $v: previously submitted values (the raw $_POST), $default: state preselected when nothing was posted.
 */
function location_fields(array $v = [], $default = 'Enugu', $required = true) {
    $all = ng_locations();
    $state = array_key_exists('state', $v) ? (string)$v['state'] : $default;
    $lga = (string)($v['lga'] ?? '');
    $foreign = $state === LOC_OTHER;
    $lgaOther = !$foreign && $lga === LOC_OTHER;
    $req = $required ? ' required' : '';
    ?>
    <div class="location-picker" data-src="<?php echo e(BASE_URL . '/assets/data/ng-locations.json'); ?>">
        <div class="form-row">
            <div class="form-group">
                <label for="loc_state">State<?php echo $required ? ' *' : ''; ?></label>
                <select id="loc_state" name="state" class="form-control"<?php echo $req; ?>>
                    <option value="">Select state</option>
                    <?php foreach (array_keys($all) as $s): ?>
                    <option value="<?php echo e($s); ?>" <?php echo $state === $s ? 'selected' : ''; ?>><?php echo e($s === 'Federal Capital Territory' ? 'FCT (Abuja)' : $s); ?></option>
                    <?php endforeach; ?>
                    <option value="<?php echo LOC_OTHER; ?>" <?php echo $foreign ? 'selected' : ''; ?>>Other (I live outside Nigeria)</option>
                </select>
            </div>
            <div class="form-group loc-lga"<?php echo $foreign ? ' hidden' : ''; ?>>
                <label for="loc_lga">Local Government Area<?php echo $required ? ' *' : ''; ?></label>
                <select id="loc_lga" name="lga" class="form-control"<?php echo $foreign ? '' : $req; ?>>
                    <option value=""><?php echo isset($all[$state]) ? 'Select local government' : 'Select a state first'; ?></option>
                    <?php foreach (($all[$state] ?? []) as $l): ?>
                    <option value="<?php echo e($l); ?>" <?php echo $lga === $l ? 'selected' : ''; ?>><?php echo e($l); ?></option>
                    <?php endforeach; ?>
                    <?php if (isset($all[$state])): ?><option value="<?php echo LOC_OTHER; ?>" <?php echo $lgaOther ? 'selected' : ''; ?>>Other (not listed)</option><?php endif; ?>
                </select>
            </div>
        </div>
        <div class="form-group loc-lga-other"<?php echo $lgaOther ? '' : ' hidden'; ?>>
            <label for="loc_lga_other_ng">Type your local government area *</label>
            <input type="text" id="loc_lga_other_ng" name="lga_other" class="form-control" maxlength="100" value="<?php echo e($lgaOther ? ($v['lga_other'] ?? '') : ''); ?>">
        </div>
        <div class="loc-foreign"<?php echo $foreign ? '' : ' hidden'; ?>>
            <div class="form-row">
                <div class="form-group"><label for="loc_country">Country *</label>
                    <input type="text" id="loc_country" name="country_other" class="form-control" maxlength="100" autocomplete="country-name" value="<?php echo e($foreign ? ($v['country_other'] ?? '') : ''); ?>"></div>
                <div class="form-group"><label for="loc_state_other">State / Province / Region *</label>
                    <input type="text" id="loc_state_other" name="state_other" class="form-control" maxlength="100" value="<?php echo e($foreign ? ($v['state_other'] ?? '') : ''); ?>"></div>
            </div>
            <div class="form-group"><label for="loc_area_other">City / District <small class="text-muted">(optional)</small></label>
                <input type="text" id="loc_area_other" name="area_other" class="form-control" maxlength="100" value="<?php echo e($foreign ? ($v['area_other'] ?? '') : ''); ?>"></div>
        </div>
    </div>
    <?php
}

/** International phone number: optional +, 7-15 digits, spaces/dashes/brackets allowed. */
function valid_phone_intl($phone) {
    $phone = trim((string)$phone);
    if (!preg_match('/^\+?[0-9][0-9\s\-().]{5,22}$/', $phone)) return false;
    $digits = strlen(preg_replace('/\D/', '', $phone));
    return $digits >= 7 && $digits <= 15;
}
