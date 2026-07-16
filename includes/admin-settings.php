<?php
/**
 * Admin settings screen: webhook URL field, security token display/rotation,
 * and the generated Apps Script setup code.
 *
 * @package CV_Lead_To_Sheet_Bridge
 * @author  CV Infotech
 * @link    https://cvinfotech.com
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'cv_lts_add_menu' );
add_action( 'admin_init', 'cv_lts_settings_init' );
add_action( 'admin_post_cv_lts_regenerate_token', 'cv_lts_handle_regenerate_token' );

/**
 * Get the security token, generating one on first use.
 *
 * NOTE: This is deliberately NOT registered via register_setting()/options.php.
 * If it were part of the same settings group as the webhook URL field, saving
 * the "Save Webhook URL" form (which doesn't include a token input) would blank
 * the token out — options.php sets any registered option missing from $_POST
 * to null. Managing it via direct get_option()/update_option() calls avoids that.
 */
function cv_lts_get_security_token() {
    $token = get_option( 'cv_lts_security_token' );
    if ( empty( $token ) ) {
        $token = cv_lts_generate_token();
        update_option( 'cv_lts_security_token', $token );
    }
    return $token;
}

function cv_lts_generate_token() {
    return wp_generate_password( 32, false, false );
}

/**
 * Handles the "Regenerate Token" button. A regenerated token immediately
 * invalidates the old one - the Apps Script code box will show the new value,
 * but the person needs to re-paste the script into their Google Sheet.
 */
function cv_lts_handle_regenerate_token() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to do this.', 'cv-lts' ) );
    }
    check_admin_referer( 'cv_lts_regenerate_token_action', 'cv_lts_regenerate_nonce' );

    update_option( 'cv_lts_security_token', cv_lts_generate_token() );

    wp_safe_redirect( admin_url( 'options-general.php?page=cv-lts-settings&token_regenerated=1' ) );
    exit;
}

function cv_lts_add_menu() {
    add_options_page(
        'CV Lead-to-Sheet Settings',
        'CV Lead-to-Sheet',
        'manage_options',
        'cv-lts-settings',
        'cv_lts_settings_page'
    );
}

function cv_lts_settings_init() {
    register_setting( 'cv_lts_group', 'cv_lts_webhook_url', array(
        'sanitize_callback' => 'cv_lts_sanitize_webhook_url',
    ));

    add_settings_section( 'cv_lts_main_section', 'API Configuration', null, 'cv-lts-settings' );

    add_settings_field(
        'cv_lts_webhook_url',
        'Google Script Webhook URL',
        'cv_lts_url_render',
        'cv-lts-settings',
        'cv_lts_main_section'
    );
}

/**
 * Sanitizes the webhook URL and enforces HTTPS. Lead data (names, emails,
 * message bodies) travels in this request's POST body, so an http:// endpoint
 * would send it in plaintext. Rejecting non-https input here, rather than
 * only at send-time, keeps the option itself from ever holding an insecure
 * value and gives the admin an explicit reason via settings_errors().
 */
function cv_lts_sanitize_webhook_url( $url ) {
    $url = esc_url_raw( trim( (string) $url ) );

    if ( empty( $url ) ) {
        return '';
    }

    if ( 0 !== strpos( $url, 'https://' ) ) {
        add_settings_error(
            'cv_lts_webhook_url',
            'cv_lts_webhook_url_not_https',
            __( 'The webhook URL must start with https://. Lead data would otherwise be sent in plaintext. The previous value was kept.', 'cv-lts' )
        );
        return get_option( 'cv_lts_webhook_url' );
    }

    return $url;
}

function cv_lts_url_render() {
    $val = get_option( 'cv_lts_webhook_url' );
    echo '<input type="url" name="cv_lts_webhook_url" value="' . esc_attr( $val ) . '" class="regular-text" placeholder="https://script.google.com/macros/s/.../exec">';
    echo '<p class="description">Paste your deployed Google Web App URL here.</p>';
}

function cv_lts_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $token = cv_lts_get_security_token();

    // The Apps Script code has the token baked in at generation time, so
    // copy/pasting it "just works" without the user editing anything.
    $apps_script_code = <<<JS
// ==== CV Lead-to-Sheet Bridge :: Apps Script receiver ====
// This token must match the "Security Token" shown in your WordPress
// plugin settings. Requests without a matching token are rejected.
// Apps Script web apps can't read custom HTTP headers, so the token
// travels as a normal POST field (cv_lts_token) instead.
var CV_LTS_SECURITY_TOKEN = "{$token}";

function doPost(e) {
  try {
    var data = e.parameter;

    // --- Security check ---
    if (!data.cv_lts_token || data.cv_lts_token !== CV_LTS_SECURITY_TOKEN) {
      return ContentService
        .createTextOutput("Error: Invalid or missing security token.")
        .setMimeType(ContentService.MimeType.TEXT);
    }

    var sheet = getOrCreateSheetForForm(SpreadsheetApp.getActiveSpreadsheet(), data.form_source);
    var headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
    var newRow = [];

    for (var i = 0; i < headers.length; i++) {
      var headerName = normalizeKey(headers[i].toString(), true);

      if (headerName === 'date' || headerName === 'timestamp') {
        newRow.push(new Date());
        continue;
      }

      var foundValue = "";
      for (var key in data) {
        if (key === 'cv_lts_token') continue; // never write the token into the sheet
        var cleanKey = normalizeKey(key, true);
        if (cleanKey === headerName) {
          foundValue = sanitizeCellValue(data[key]);
          break;
        }
      }
      newRow.push(foundValue);
    }

    sheet.appendRow(newRow);
    return ContentService.createTextOutput("Success").setMimeType(ContentService.MimeType.TEXT);
  } catch (error) {
    return ContentService.createTextOutput("Error: " + error.message);
  }
}

// Routes each submission to a tab named after its form (the "Form Source"
// value sent by the plugin). If no tab with that exact name exists yet,
// one is created automatically, with its header row copied from the very
// first tab in the spreadsheet (treated as the master template) so
// Smart-Match keeps working on the new tab immediately. Submissions with
// no form_source (or from an older plugin version that doesn't send one)
// fall back to the currently active tab, preserving the old single-sheet
// behavior.
function getOrCreateSheetForForm(ss, formSourceName) {
  var name = formSourceName ? formSourceName.toString().trim() : "";
  if (!name) {
    return ss.getActiveSheet();
  }

  name = sanitizeSheetName(name);
  var sheet = ss.getSheetByName(name);
  if (sheet) {
    return sheet;
  }

  var templateSheet = ss.getSheets()[0];
  sheet = ss.insertSheet(name);

  var lastCol = templateSheet.getLastColumn();
  if (lastCol > 0) {
    var templateHeaders = templateSheet.getRange(1, 1, 1, lastCol).getValues();
    sheet.getRange(1, 1, 1, lastCol).setValues(templateHeaders);
  }

  return sheet;
}

// Google Sheets tab names can't contain [ ] * ? / \ or : , can't be blank,
// and are capped at 100 characters - strip/trim accordingly so an unusual
// form title (e.g. containing a slash) can't break sheet creation.
function sanitizeSheetName(name) {
  var clean = name.replace(/[\[\]\*\?\/\\:]/g, "").trim();
  if (!clean) {
    clean = "Form Leads";
  }
  return clean.substring(0, 100);
}

// Prevents spreadsheet formula injection. Every value here comes from an
// anonymous, unauthenticated form submission - if a visitor submits a field
// like "=IMPORTXML(...)" or "@SUM(...)" it would otherwise be evaluated as a
// live formula the moment the sheet owner opens the file (a known attack
// class: CSV/Formula Injection). A leading apostrophe is Sheets' own
// convention for "treat this as literal text," so this neutralizes the
// value without altering what's actually stored/displayed.
function sanitizeCellValue(value) {
  var str = value === null || value === undefined ? "" : value.toString();
  if (/^[=+\-@]/.test(str)) {
    return "'" + str;
  }
  return str;
}

// Normalizes a header/field name to lowercase alphanumeric. When
// stripYourPrefix is true, a LEADING "your" (from CF7's your-name,
// your-email, etc.) is removed - anchored to the start of the string
// so it can't accidentally eat "your" out of the middle of an
// unrelated field name (e.g. "storeyourinfo" would no longer become
// "storeinfo"). Applied to BOTH sheet headers and incoming field keys
// so a header named "Email" and one named "Your Email" (copy-pasted
// straight from a CF7 form label) resolve to the same normalized key
// and still match.
function normalizeKey(str, stripYourPrefix) {
  var clean = str.toString().toLowerCase().replace(/[^a-z0-9]/g, "");
  if (stripYourPrefix) {
    clean = clean.replace(/^your/, "");
  }
  return clean;
}
JS;

    ?>
    <div class="wrap" style="max-width: 800px;">
        <div style="display: flex; align-items: center; margin-bottom: 20px;">
            <h1 style="flex-grow: 1;">CV Lead-to-Sheet Bridge</h1>
            <img src="<?php echo esc_url( CV_LTS_URL . 'assets/logo-mark.png' ); ?>" alt="CV Infotech" style="height: 40px;">
        </div>

        <?php if ( isset( $_GET['token_regenerated'] ) ) : ?>
            <div class="notice notice-success"><p><strong>New security token generated.</strong> Copy the updated Apps Script code below and re-paste it into your Google Sheet, then redeploy.</p></div>
        <?php endif; ?>

        <?php settings_errors( 'cv_lts_webhook_url' ); ?>

        <div class="card" style="padding: 20px; border: 1px solid #ccc; background: #fff;">
            <form action="options.php" method="POST">
                <?php
                settings_fields( 'cv_lts_group' );
                do_settings_sections( 'cv-lts-settings' );
                submit_button( 'Save Webhook URL' );
                ?>
            </form>
        </div>

        <div class="card" style="padding: 20px; border: 1px solid #ccc; background: #fff; margin-top: 20px;">
            <h2 style="margin-top:0;">Security Token</h2>
            <p class="description">This token is embedded in the Apps Script code below and checked on every request. Anyone without it cannot write to your sheet, even if they discover your webhook URL.</p>
            <input type="text" readonly value="<?php echo esc_attr( $token ); ?>" class="regular-text code" style="max-width:400px;" onclick="this.select();">
            <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="POST" style="display:inline-block; margin-left:10px;">
                <input type="hidden" name="action" value="cv_lts_regenerate_token">
                <?php wp_nonce_field( 'cv_lts_regenerate_token_action', 'cv_lts_regenerate_nonce' ); ?>
                <?php submit_button( 'Regenerate Token', 'secondary', 'submit', false, array( 'onclick' => "return confirm('This invalidates the current token. You will need to update the Apps Script code in your Google Sheet. Continue?');" ) ); ?>
            </form>
        </div>

        <hr style="margin: 30px 0;">

        <h2>How to Setup (No Coding Required)</h2>
        <ol>
            <li>Create a <strong>Google Sheet</strong> and add your headers (e.g., Date, Name, Email) in Row 1. Add a column named <strong>"Form Source"</strong> to Row 1 of your Google Sheet to automatically track which form (e.g., "Contact Us" vs "Quote Request") sent the lead.</li>
            <li>Go to <strong>Extensions > Apps Script</strong>.</li>
            <li>Delete all existing code and paste the code below (your security token is already included):</li>
        </ol>

        <p class="description" style="margin-top:-10px;"><strong>Multiple forms, one spreadsheet:</strong> each form now gets its own tab automatically. The first submission from a given form creates a new tab named after that form, with headers copied from your first tab, and every later submission from that same form goes to its matching tab. Leads with no detectable form name fall back to whichever tab is currently active.</p>

        <textarea readonly style="width:100%; height:340px; background:#f9f9f9; font-family:monospace; padding:15px; border:1px solid #ddd;" id="cv_code_box"><?php echo esc_textarea( $apps_script_code ); ?></textarea>

        <button type="button" class="button button-secondary" onclick="document.getElementById('cv_code_box').select(); document.execCommand('copy'); alert('Code copied to clipboard!');" style="margin-top:10px;">Copy Code to Clipboard</button>

        <ol start="4">
            <li>Click <strong>Deploy > New Deployment</strong>.</li>
            <li>Select <strong>Type: Web App</strong>. Change Access to <strong>"Anyone"</strong>.</li>
            <li>Copy the <strong>Web App URL</strong> and paste it into the box at the top of this page.</li>
        </ol>

        <div style="margin-top: 40px; padding: 15px; background: #e7f3ff; border-left: 4px solid #2271b1;">
            <p><strong>Need Custom Development?</strong> This plugin is maintained by <a href="https://cvinfotech.com" target="_blank">CV Infotech</a>. Contact us for professional WordPress solutions.</p>
        </div>
    </div>
    <?php
}
