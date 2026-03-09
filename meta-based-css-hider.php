<?php
/**
 * Plugin Name: Meta-based CSS Hider
 * Description: Blendet HTML-Elemente per CSS aus, wenn eine User-Meta-Bedingung erfüllt ist. Operatoren: =, !=, contains, not_contains, in, not_in, regex. Optional auch im Admin.
 * Version:     1.1.0
 * Author:      Remo Lepori
 * License:     GPLv2 or later
 * Text Domain: mbch
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('MBCH_Plugin')):

final class MBCH_Plugin {
  const OPT_RULES      = 'mbch_rules_v1';       // Array von Regeln
  const OPT_IN_ADMIN   = 'mbch_apply_admin_v1'; // 0|1: auch im Admin anwenden

  public function __construct() {
    add_action('admin_menu',            array($this, 'add_settings_page'));
    add_action('admin_init',            array($this, 'register_settings'));
    add_action('wp_enqueue_scripts',    array($this, 'maybe_print_css'), 9999);
    add_action('admin_enqueue_scripts', array($this, 'maybe_print_css_admin'), 9999);
  }

  /** Settings registrieren */
  public function register_settings() {
    register_setting('mbch_group', self::OPT_RULES, array(
      'type'              => 'array',
      'sanitize_callback' => array($this, 'sanitize_rules'),
      'default'           => array(),
      'show_in_rest'      => false,
    ));

    register_setting('mbch_group', self::OPT_IN_ADMIN, array(
      'type'              => 'boolean',
      'sanitize_callback' => function($v){ return !empty($v) ? 1 : 0; },
      'default'           => 0,
      'show_in_rest'      => false,
    ));
  }

  /** Admin-Menü */
  public function add_settings_page() {
    add_options_page(
      __('Meta-based CSS Hider', 'mbch'),
      __('Meta-CSS Hider', 'mbch'),
      'manage_options',
      'mbch',
      array($this, 'render_settings_page')
    );
  }

  /** Alle existierenden User-Meta-Keys aus der DB (mit kurzem Cache) */
  private function get_all_user_meta_keys($include_private = false) {
    global $wpdb;

    $cache_key = 'mbch_meta_keys_' . ($include_private ? 'all' : 'public');
    $cached = get_transient($cache_key);
    if ($cached !== false && is_array($cached)) {
      return $cached;
    }

    $table = $wpdb->usermeta;
    $sql   = "SELECT DISTINCT meta_key FROM {$table}";
    $keys  = $wpdb->get_col($sql);
    if (!is_array($keys)) $keys = array();

    $out = array();
    foreach ($keys as $k) {
      $k = (string)$k;
      if ($k === '') continue;
      if (!$include_private && substr($k, 0, 1) === '_') continue; // Private Keys optional ausblenden
      $out[] = $k;
    }

    $out = array_values(array_unique($out, SORT_REGULAR));
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);

    set_transient($cache_key, $out, 10 * MINUTE_IN_SECONDS); // 10 Minuten Cache
    return $out;
  }

  /** Settings-UI rendern */
  public function render_settings_page() {
    if (!current_user_can('manage_options')) return;

    $rules      = get_option(self::OPT_RULES, array());
    $applyAdmin = (bool) get_option(self::OPT_IN_ADMIN, 0);
    $meta_keys  = $this->get_all_user_meta_keys(false); // nur öffentliche Keys

    if (!is_array($rules)) $rules = array();
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Meta-based CSS Hider', 'mbch'); ?></h1>
      <p class="description">
        <?php esc_html_e('Wenn eine User-Meta-Bedingung erfüllt ist, werden definierte CSS-Selektoren ausgeblendet.', 'mbch'); ?>
      </p>

      <form method="post" action="options.php" id="mbch-form">
        <?php settings_fields('mbch_group'); ?>

        <table class="form-table" role="presentation">
          <tbody>
            <tr>
              <th scope="row"><?php esc_html_e('Auch im Admin anwenden', 'mbch'); ?></th>
              <td>
                <label>
                  <input type="checkbox" name="<?php echo esc_attr(self::OPT_IN_ADMIN); ?>" value="1" <?php checked($applyAdmin, true); ?> />
                  <?php esc_html_e('Regeln auch im WordPress-Admin aktivieren (Vorsicht bei Selektoren!).', 'mbch'); ?>
                </label>
              </td>
            </tr>
          </tbody>
        </table>

        <hr />

        <h2><?php esc_html_e('Regeln', 'mbch'); ?></h2>
        <p class="description">
          <?php esc_html_e('Pro Regel: Meta-Key, Operator, Wert und die zu versteckenden CSS-Selektoren (ein Selektor pro Zeile).', 'mbch'); ?><br/>
          <?php esc_html_e('Operatoren: =, !=, contains, not_contains, in, not_in, regex', 'mbch'); ?>
        </p>

        <div id="mbch-rules">
          <?php
          if (empty($rules)) { $rules = array( $this->blank_rule() ); }
          $max_idx = -1;
          foreach ($rules as $idx => $rule) {
            echo $this->render_rule_row($idx, $rule, $meta_keys);
            if ((int)$idx > $max_idx) $max_idx = (int)$idx;
          }
          ?>
        </div>

        <p><button type="button" class="button" id="mbch-add-rule"><?php esc_html_e('Regel hinzufügen', 'mbch'); ?></button></p>

        <?php submit_button(); ?>
      </form>
    </div>

    <script>
      (function(){
        var container = document.getElementById('mbch-rules');
        var addBtn = document.getElementById('mbch-add-rule');
        var nextIndex = <?php echo (int)$max_idx + 1; ?>;

        function template(i) {
          return `
          <?php echo $this->escape_for_js($this->render_rule_row('{{i}}', $this->blank_rule(), $meta_keys)); ?>
          `.replaceAll('{{i}}', i);
        }

        addBtn.addEventListener('click', function(){
          var wrapper = document.createElement('div');
          wrapper.innerHTML = template(nextIndex);
          container.appendChild(wrapper.firstElementChild);
          nextIndex++;
        });

        container.addEventListener('click', function(e){
          if (e.target && e.target.classList.contains('mbch-remove')) {
            e.preventDefault();
            var box = e.target.closest('.mbch-rule');
            if (box) box.remove();
          }
        });
      })();
    </script>
    <style>
      .mbch-rule { background:#fff; border:1px solid #ccd0d4; border-radius:6px; padding:12px; margin:12px 0; }
      .mbch-grid { display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px; }
      .mbch-grid .field { display:flex; flex-direction:column; }
      .mbch-selectors textarea { width:100%; min-height:110px; font-family:monospace; }
      .mbch-inline { display:flex; gap:12px; align-items:center; }
    </style>
    <?php
  }

  /** Eine Regelzeile rendern */
  private function render_rule_row($i, $rule, $meta_keys = array()) {
    $meta_key   = esc_attr(isset($rule['meta_key']) ? $rule['meta_key'] : '');
    $operator   = esc_attr(isset($rule['operator']) ? $rule['operator'] : '=');
    $value      = esc_attr(isset($rule['value']) ? $rule['value'] : '');
    $case_ins   = !empty($rule['case_insensitive']) ? 1 : 0;
    $selectors  = (isset($rule['selectors']) && is_array($rule['selectors'])) ? implode("\n", $rule['selectors']) : '';
    ob_start(); ?>
      <div class="mbch-rule">
        <div class="mbch-grid">
          <div class="field">
            <label><strong><?php esc_html_e('Meta-Key', 'mbch'); ?></strong></label>
            <?php if (!empty($meta_keys)) :
              $current_key = $meta_key;
              $in_list = in_array($current_key, $meta_keys, true);
            ?>
              <select name="<?php echo esc_attr(self::OPT_RULES . '['.$i.'][meta_key]'); ?>" style="max-width:100%;">
                <?php if (!$in_list && $current_key !== ''): ?>
                  <option value="<?php echo esc_attr($current_key); ?>" selected><?php echo esc_html($current_key . ' ' . __('(nicht in Liste)', 'mbch')); ?></option>
                <?php endif; ?>
                <?php foreach ($meta_keys as $k): ?>
                  <option value="<?php echo esc_attr($k); ?>" <?php selected($current_key, $k); ?>><?php echo esc_html($k); ?></option>
                <?php endforeach; ?>
              </select>
              <p class="description"><?php esc_html_e('Vorhandene User-Meta-Schlüssel (aus wp_usermeta).', 'mbch'); ?></p>
            <?php else: ?>
              <input type="text" name="<?php echo esc_attr(self::OPT_RULES . '['.$i.'][meta_key]'); ?>" value="<?php echo $meta_key; ?>" placeholder="z.B. membership_level" />
              <p class="description"><?php esc_html_e('Keine Meta-Keys gefunden. Trage den Key manuell ein.', 'mbch'); ?></p>
            <?php endif; ?>
          </div>
          <div class="field">
            <label><strong><?php esc_html_e('Operator', 'mbch'); ?></strong></label>
            <select name="<?php echo esc_attr(self::OPT_RULES . '['.$i.'][operator]'); ?>">
              <?php
              $ops = array('='=>'= (gleich)','!='=>'!= (ungleich)','contains'=>'contains','not_contains'=>'not_contains','in'=>'in (kommagetrennt)','not_in'=>'not_in (kommagetrennt)','regex'=>'regex (PHP)');
              foreach ($ops as $k=>$lbl) {
                printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($operator,$k,false), esc_html($lbl));
              }
              ?>
            </select>
          </div>
          <div class="field">
            <label><strong><?php esc_html_e('Wert', 'mbch'); ?></strong></label>
            <input type="text" name="<?php echo esc_attr(self::OPT_RULES . '['.$i.'][value]'); ?>" value="<?php echo $value; ?>" placeholder="z.B. pro oder 1,2,3 für in/not_in" />
          </div>
        </div>

        <div class="mbch-inline" style="margin-top:10px;">
          <label><input type="checkbox" name="<?php echo esc_attr(self::OPT_RULES . '['.$i.'][case_insensitive]'); ?>" value="1" <?php checked($case_ins,1); ?> /> <?php esc_html_e('Groß-/Kleinschreibung ignorieren', 'mbch'); ?></label>
          <a href="#" class="button-link-delete mbch-remove"><?php esc_html_e('Regel entfernen', 'mbch'); ?></a>
        </div>

        <div class="mbch-selectors" style="margin-top:10px;">
          <label><strong><?php esc_html_e('CSS-Selektoren (ein Selektor pro Zeile)', 'mbch'); ?></strong></label>
          <textarea name="<?php echo esc_attr(self::OPT_RULES . '['.$i.'][selectors]'); ?>" placeholder=".nur-pro
#promo
header .login"><?php echo esc_textarea($selectors); ?></textarea>
        </div>
      </div>
    <?php
    return ob_get_clean();
  }

  /** Leere Regel */
  private function blank_rule() {
    return array(
      'meta_key'         => '',
      'operator'         => '=',
      'value'            => '',
      'case_insensitive' => 1,
      'selectors'        => array(),
    );
  }

  /** Inline-CSS im Frontend ausgeben */
  public function maybe_print_css() {
    $css = $this->build_css_for_current_user();
    if ($css) {
      wp_register_style('mbch-inline', false, array(), '1.1.0');
      wp_enqueue_style('mbch-inline');
      wp_add_inline_style('mbch-inline', $css);
    }
  }

  /** Optional auch im Admin */
  public function maybe_print_css_admin() {
    if (!(bool) get_option(self::OPT_IN_ADMIN, 0)) return;
    $css = $this->build_css_for_current_user();
    if ($css) {
      wp_register_style('mbch-admin-inline', false, array(), '1.1.0');
      wp_enqueue_style('mbch-admin-inline');
      wp_add_inline_style('mbch-admin-inline', $css);
    }
  }

  /** CSS für aktuellen User (wenn Regeln matchen) */
  private function build_css_for_current_user() {
    if (!is_user_logged_in()) {
      return '';
    }
    $user = wp_get_current_user();
    if (!$user || empty($user->ID)) return '';

    $rules = get_option(self::OPT_RULES, array());
    if (empty($rules) || !is_array($rules)) return '';

    $all_selectors = array();

    foreach ($rules as $rule) {
      if ($this->rule_matches_user($user->ID, $rule)) {
        $sels = $this->sanitize_selectors(isset($rule['selectors']) ? $rule['selectors'] : array());
        if (!empty($sels)) {
          $all_selectors = array_merge($all_selectors, $sels);
        }
      }
    }

    $all_selectors = array_values(array_unique($all_selectors));
    if (empty($all_selectors)) return '';

    $css  = implode(",\n", $all_selectors);
    $css .= " {\n  display: none !important;\n  visibility: hidden !important;\n  pointer-events: none !important;\n}\n";

    return $css;
  }

  /** Regel-Matching */
  private function rule_matches_user($user_id, $rule) {
    $meta_key = isset($rule['meta_key']) ? trim((string)$rule['meta_key']) : '';
    $operator = isset($rule['operator']) ? trim((string)$rule['operator']) : '=';
    $value    = isset($rule['value'])    ? (string)$rule['value'] : '';
    $ci       = !empty($rule['case_insensitive']);

    if ($meta_key === '') return false;

    // alle Werte zu Key holen (array möglich)
    $values = get_user_meta($user_id, $meta_key, false);
    if (empty($values)) {
      if (in_array($operator, array('!=','not_contains','not_in'), true)) {
        $values = array(''); // behandle "nicht vorhanden" wie leeren String
      } else {
        return false;
      }
    }

    foreach ($values as $userVal) {
      if (is_array($userVal) || is_object($userVal)) $userVal = wp_json_encode($userVal);
      $userVal = (string)$userVal;

      $userVal_cmp = $ci ? $this->mb_to_lower($userVal) : $userVal;
      $target_cmp  = $ci ? $this->mb_to_lower($value)   : $value;

      switch ($operator) {
        case '=':
          if ($userVal_cmp === $target_cmp) return true;
          break;

        case '!=':
          if ($userVal_cmp !== $target_cmp) return true;
          break;

        case 'contains':
          if ($target_cmp !== '' && $this->mb_pos($userVal_cmp, $target_cmp) !== false) return true;
          break;

        case 'not_contains':
          if ($target_cmp === '' || $this->mb_pos($userVal_cmp, $target_cmp) === false) return true;
          break;

        case 'in':
          $set = $this->csv_to_array($target_cmp, $ci);
          if (!empty($set) && in_array($userVal_cmp, $set, true)) return true;
          break;

        case 'not_in':
          $set = $this->csv_to_array($target_cmp, $ci);
          if (empty($set) || !in_array($userVal_cmp, $set, true)) return true;
          break;

        case 'regex':
          $pattern = $value;
          if (@preg_match($pattern, '') === false) {
            $flags = $ci ? 'i' : '';
            $pattern = '#'.str_replace('#','\#',$value).'#'.$flags;
          }
          $ok = @preg_match($pattern, (string)$userVal);
          if ($ok === 1) return true;
          break;

        default:
          return false;
      }
    }

    return false;
  }

  /** Selektoren säubern */
  private function sanitize_selectors($in) {
    $list = array();
    if (is_string($in)) {
      $lines = preg_split('/\r\n|\r|\n/', $in);
    } elseif (is_array($in)) {
      $lines = $in;
    } else {
      $lines = array();
    }

    foreach ($lines as $sel) {
      $s = trim(wp_strip_all_tags((string)$sel));
      if ($s === '') continue;
      if (!preg_match('/^[A-Za-z0-9\s\.\#\-\_\:\,>\+\~\*\=\^\$\[\]\(\)\"\'\\\\]+$/', $s)) continue;
      $list[] = $s;
    }
    return array_values(array_unique($list));
  }

  /** Eingaben sanitizen */
  public function sanitize_rules($input) {
    $out = array();
    if (!is_array($input)) return $out;

    foreach ($input as $idx => $rule) {
      $meta_key = isset($rule['meta_key']) ? trim((string)$rule['meta_key']) : '';
      $op       = isset($rule['operator']) ? trim((string)$rule['operator']) : '=';
      $value    = isset($rule['value'])    ? (string)$rule['value'] : '';
      $ci       = !empty($rule['case_insensitive']) ? 1 : 0;
      $sels     = $this->sanitize_selectors(isset($rule['selectors']) ? $rule['selectors'] : array());

      if ($meta_key === '' || empty($sels)) continue;

      if (!in_array($op, array('=','!=','contains','not_contains','in','not_in','regex'), true)) {
        $op = '=';
      }

      $out[] = array(
        'meta_key'         => $meta_key,
        'operator'         => $op,
        'value'            => $value,
        'case_insensitive' => $ci,
        'selectors'        => $sels,
      );
    }
    return $out;
  }

  /** JS-Template helfen */
  private function escape_for_js($html) {
    return str_replace(array("\n", "\r", "'"), array('', '', "\\'"), $html);
  }

  /* ---------- Helfer ohne mbstring-Abhängigkeit ---------- */

  private function mb_to_lower($s) {
    $s = (string)$s;
    return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
  }

  private function mb_pos($haystack, $needle) {
    $haystack = (string)$haystack;
    $needle   = (string)$needle;
    if (function_exists('mb_strpos')) return mb_strpos($haystack, $needle);
    return strpos($haystack, $needle);
  }

  private function csv_to_array($csv, $ci) {
    $parts = array_map('trim', explode(',', (string)$csv));
    $parts = array_filter($parts, function($v){ return $v !== ''; });
    if ($ci) {
      $out = array();
      foreach ($parts as $p) { $out[] = $this->mb_to_lower($p); }
      return $out;
    }
    return array_values($parts);
  }

  /** Uninstall */
  public static function on_uninstall() {
    delete_option(self::OPT_RULES);
    delete_option(self::OPT_IN_ADMIN);
  }
}

endif; // class exists

// Init
add_action('plugins_loaded', function(){
  if (class_exists('MBCH_Plugin')) {
    $GLOBALS['mbch_plugin'] = new MBCH_Plugin();
  }
});

// Uninstall-Hook robust außerhalb registrieren
if (function_exists('register_uninstall_hook')) {
  register_uninstall_hook(__FILE__, array('MBCH_Plugin', 'on_uninstall'));
}
