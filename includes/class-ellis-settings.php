<?php
class Ellis_Settings_Page extends WC_Settings_Page {
    public function __construct() {
        $this->id    = 'ellis_app_settings';
        $this->label = 'Ellis App Config';

        // Hook to add our custom JavaScript and CSS for the copy button
        add_action( 'admin_footer', array( $this, 'add_copy_script' ) );

        parent::__construct();
    }

    public function get_settings() {
        // Get all available WC statuses for the dropdown
        $statuses = wc_get_order_statuses();

        return array(
            array(
                'title' => 'EllisApp Webhook Settings',
                'type'  => 'title',
                'id'    => 'ellis_settings_title',
                'desc'  => 'Configure where your WooCommerce order data should be sent.'
            ),
            array(
                'title'       => 'Destination URL',
                'id'          => 'ellis_webhook_url',
                'type'        => 'text',
                'placeholder' => 'https://your-server.com/api/post',
                'desc'        => 'Enter the full URL where the JSON webhook will be sent.',
                'desc_tip'    => true,
                'css'         => 'min-width: 400px;'
            ),
            array(
                'title'             => 'Bearer Token',
                'id'                => 'ellis_bearer_token',
                'type'              => 'text',
                'desc'              => 'This secure token is auto-generated. Use it in the header of your external requests to authenticate.<br><br><button type="button" class="button button-secondary" id="copy_ellis_token">Copy token</button><span id="ellis_token_copied_msg">Bearer copied!</span>',
                'css'               => 'min-width: 400px; background-color: #f0f0f1; cursor: not-allowed;',
                'custom_attributes' => array( 'readonly' => 'readonly' )
            ),
            array(
                'title'    => 'Order Type',
                'id'       => 'ellis_order_type',
                'type'     => 'select',
                'class'    => 'wc-enhanced-select',
                'css'      => 'min-width: 400px;',
                'default'  => 'Payment',
                'options'  => array(
                    'Payment' => 'Payment',
                    'Order'   => 'Order',
                ),
                'desc'     => 'Select the value to be sent as the "Type" variable in the JSON payload.',
                'desc_tip' => true,
            ),
            array(
                'title'       => 'Delivery Name',
                'id'          => 'ellis_delivery_name',
                'type'        => 'text',
                'default'     => 'Доставка',
                'placeholder' => 'Доставка',
                'desc'        => 'Name used for the shipping line item in the Products array.',
                'desc_tip'    => true,
                'css'         => 'min-width: 400px;'
            ),
            array(
                'title'    => 'Trigger Statuses',
                'id'       => 'ellis_trigger_statuses',
                'type'     => 'multiselect',
                'class'    => 'wc-enhanced-select',
                'css'      => 'min-width: 400px;',
                'default'  => array( 'wc-processing', 'wc-completed' ),
                'options'  => $statuses,
                'desc'     => 'The webhook will only fire when an order moves into one of these selected statuses.',
                'desc_tip' => true,
            ),
            array( 'type' => 'sectionend', 'id' => 'ellis_settings_title' ),
        );
    }

    /**
     * Injects the CSS and JavaScript required to copy the token to the clipboard.
     * Only loads on this specific settings tab.
     */
    public function add_copy_script() {
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'wc-settings' && isset( $_GET['tab'] ) && $_GET['tab'] === $this->id ) {
            ?>
            <style>
                #ellis_token_copied_msg {
                    display: none;
                    color: #008a20;
                    margin-left: 10px;
                    font-weight: 600;
                }
            </style>
            <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                var copyBtn = document.getElementById('copy_ellis_token');
                var tokenField = document.getElementById('ellis_bearer_token');
                var msgSpan = document.getElementById('ellis_token_copied_msg');

                if(copyBtn && tokenField) {
                    copyBtn.addEventListener('click', function(e) {
                        e.preventDefault();

                        if (navigator.clipboard) {
                            navigator.clipboard.writeText(tokenField.value).then(showSuccessMessage);
                        } else {
                            tokenField.select();
                            document.execCommand('copy');
                            showSuccessMessage();
                        }

                        function showSuccessMessage() {
                            msgSpan.style.display = 'inline-block';
                            setTimeout(function() {
                                msgSpan.style.display = 'none';
                            }, 2500);
                        }
                    });
                }
            });
            </script>
            <?php
        }
    }
}
return new Ellis_Settings_Page();
