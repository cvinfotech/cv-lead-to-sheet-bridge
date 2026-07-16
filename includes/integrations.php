<?php
/**
 * Form-plugin hooks (CF7, WPForms, Gravity Forms, Elementor Pro Forms) and the
 * shared webhook dispatcher that POSTs normalized field data to the paired
 * Google Apps Script, with a security token embedded in the request body.
 *
 * @package CV_Lead_To_Sheet_Bridge
 * @author  CV Infotech
 * @link    https://cvinfotech.com
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Common function to send data to Google
 *
 * SECURITY NOTE ON THE TOKEN:
 * Google Apps Script web apps do NOT expose incoming HTTP headers to doPost(e) —
 * there is no e.headers object. So a header-only token can never be validated
 * on the receiving end. To make the token actually enforceable, we send it two
 * ways:
 *   1. As a custom header (X-CV-LTS-Token) — kept for forward-compatibility,
 *      in case this webhook is ever pointed at something header-aware
 *      (e.g. a Cloudflare Worker, a custom REST endpoint, Zapier, etc).
 *   2. As a POST body field (cv_lts_token) — this is the one the paired
 *      Apps Script code actually checks, since e.parameter is the only
 *      thing doPost() can read.
 */
function cv_lts_send_to_webhook( $data ) {
    $url   = get_option( 'cv_lts_webhook_url' );
    $token = cv_lts_get_security_token();

    if ( empty( $url ) ) return;

    // Inject the token into the body so Apps Script can verify it via e.parameter.
    $data['cv_lts_token'] = $token;

    wp_remote_post( $url, array(
        'method'    => 'POST',
        'timeout'   => 30,
        'blocking'  => false, // Don't delay the user
        'headers'   => array(
            'X-CV-LTS-Token' => $token, // See note above: not readable by Apps Script, kept for other receivers.
        ),
        'body'      => $data,
    ));
}

/**
 * Integration: Contact Form 7
 */
add_action( 'wpcf7_mail_sent', 'cv_lts_cf7_hook' );
function cv_lts_cf7_hook( $contact_form ) {
    if ( ! class_exists( 'WPCF7_Submission' ) ) {
        return;
    }
    $submission = WPCF7_Submission::get_instance();
    if ( $submission ) {
        $data = $submission->get_posted_data();
        // Unprefixed key so it normalizes to "formsource" and matches a
        // "Form Source" sheet header via the existing normalizeKey() logic.
        $data['form_source'] = $contact_form->title();
        cv_lts_send_to_webhook( $data );
    }
}

/**
 * Integration: WPForms (Lite & Pro)
 */
add_action( 'wpforms_process_complete', 'cv_lts_wpforms_hook', 10, 4 );
function cv_lts_wpforms_hook( $fields, $entry, $form_data, $entry_id ) {
    if ( empty( $fields ) || ! is_array( $fields ) ) {
        return;
    }

    $data = array();
    foreach ( $fields as $field ) {
        if ( empty( $field['name'] ) ) {
            continue; // Unlabeled field - nothing for Smart-Match to key on.
        }
        $data[ $field['name'] ] = isset( $field['value'] ) ? $field['value'] : '';
    }

    $data['form_source'] = ! empty( $form_data['settings']['form_title'] )
        ? $form_data['settings']['form_title']
        : ( 'Form #' . $form_data['id'] );

    cv_lts_send_to_webhook( $data );
}

/**
 * Integration: Gravity Forms
 *
 * We map each field's label (not its numeric ID) to its submitted value, since
 * Smart-Match compares against human-readable sheet headers like "Name" or "Email".
 * Complex fields (Name, Address, etc.) store sub-parts under "id.inputId" keys in
 * the entry array, so we concatenate those sub-values when a field has inputs.
 */
add_action( 'gform_after_submission', 'cv_lts_gform_hook', 10, 2 );
function cv_lts_gform_hook( $entry, $form ) {
    if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) return;

    $data = array();

    foreach ( $form['fields'] as $field ) {
        $label = ! empty( $field->label ) ? $field->label : ( 'field_' . $field->id );

        if ( ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
            // Composite field (e.g. Name, Address) - stitch sub-inputs together.
            $parts = array();
            foreach ( $field->inputs as $input ) {
                $input_id = (string) $input['id'];
                if ( isset( $entry[ $input_id ] ) && $entry[ $input_id ] !== '' ) {
                    $parts[] = $entry[ $input_id ];
                }
            }
            $value = implode( ' ', $parts );
        } else {
            $field_id = (string) $field->id;
            $value = isset( $entry[ $field_id ] ) ? $entry[ $field_id ] : '';
        }

        $data[ $label ] = $value;
    }

    $data['form_source'] = ! empty( $form['title'] ) ? $form['title'] : ( 'Form #' . $form['id'] );

    cv_lts_send_to_webhook( $data );
}

/**
 * Integration: Elementor Pro Forms
 *
 * $record->get('fields') returns each field keyed by its field ID, with a
 * 'title' (the label shown in the form editor) and 'value'. We key on title
 * so Smart-Match can line it up against sheet headers, falling back to the
 * raw field ID for fields left untitled.
 */
add_action( 'elementor_pro/forms/new_record', 'cv_lts_elementor_hook', 10, 2 );
function cv_lts_elementor_hook( $record, $handler ) {
    $raw_fields = $record->get( 'fields' );
    if ( empty( $raw_fields ) || ! is_array( $raw_fields ) ) return;

    $data = array();

    foreach ( $raw_fields as $id => $field ) {
        $label = ! empty( $field['title'] ) ? $field['title'] : $id;
        $data[ $label ] = isset( $field['value'] ) ? $field['value'] : '';
    }

    $form_name = $handler->get_settings( 'form_name' );
    $data['form_source'] = ! empty( $form_name ) ? $form_name : 'Elementor Form';

    cv_lts_send_to_webhook( $data );
}
