<?php
/**
 * The IMP_Horde_Mime_Viewer_pgp class allows viewing/decrypting of PGP
 * formatted messages.  This class implements RFC 3156.
 *
 * This class handles the following MIME types:
 *   application/pgp-encryption (in multipart/encrypted part)
 *   application/pgp-keys
 *   application/pgp-signature (in multipart/signed part)
 *
 * This class may add the following parameters to the URL:
 *   'pgp_verify_msg' - (boolean) Do verification of PGP signed data.
 *   'rawpgpkey' - (boolean) Display the PGP Public Key in raw, text format
 *
 * Copyright 2002-2008 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @author  Michael Slusarz <slusarz@horde.org>
 * @package Horde_Mime
 */
class IMP_Horde_Mime_Viewer_pgp extends Horde_Mime_Viewer_Driver
{
    /**
     * Can this driver render various views?
     *
     * @var boolean
     */
    protected $_capability = array(
        'embedded' => true,
        'full' => false,
        'info' => false,
        'inline' => true
    );

    /**
     * IMP_Horde_Crypt_PGP object.
     *
     * @var IMP_Horde_Crypt_PGP
     */
    protected $_imppgp;

    /**
     * The address of the sender.
     *
     * @var string
     */
    protected $_address = null;

    /**
     * Cache for inline data.
     *
     * @var array
     */
    static protected $_inlinecache = array();

    /**
     * Return the rendered inline version of the Horde_Mime_Part object.
     *
     * @return array  See Horde_Mime_Viewer_Driver::render().
     */
    protected function _renderInline()
    {
        if (empty($this->_imppgp) &&
            !empty($GLOBALS['conf']['utils']['gnupg'])) {
            $this->_imppgp = &Horde_Crypt::singleton(array('imp', 'pgp'));
        }

        if (Util::getFormData('rawpgpkey')) {
            return array(
                $this->_mimepart->getMimeId() => array(
                    'data' => $this->_mimepart->getContents(),
                    'status' => array(),
                    'type' => 'text/plain; charset=' . $this->_mimepart->getCharset()
                )
            );
        }

        /* Determine the address of the sender. */
        if (is_null($this->_address)) {
            $headers = $this->_params['contents']->getHeaderOb();
            $this->_address = Horde_Mime_Address::bareAddress($headers->getValue('from'));
        }

        /* We need to insert JS code if PGP support is active. */
        if (!empty($this->_imppgp) && $GLOBALS['prefs']->getValue('use_pgp')) {
            Horde::addScriptFile('prototype.js', 'horde', true);
            Horde::addScriptFile('popup.js', 'imp', true);
        }

        switch ($this->_mimepart->getType()) {
        case 'application/pgp-keys':
            return $this->_outputPGPKey();

        case 'multipart/signed':
            return $this->_outputPGPSigned();

        case 'multipart/encrypted':
            return $this->_outputPGPEncrypted();

        case 'application/pgp-encrypted':
        case 'application/pgp-signature':
        default:
            return array();
        }
    }

    /**
     * If this MIME part can contain embedded MIME parts, and those embedded
     * MIME parts exist, return an altered version of the Horde_Mime_Part that
     * contains the embedded MIME part information.
     *
     * @return mixed  A Horde_Mime_Part with the embedded MIME part information
     *                or null if no embedded MIME parts exist.
     */
    protected function _getEmbeddedMimeParts()
    {
        if ($this->_mimepart->getType() != 'multipart/encrypted') {
            return null;
        }

        $partlist = array_keys($this->_mimepart->contentTypeMap());
        $base_id = reset($partlist);
        $version_id = next($partlist);
        $data_id = Horde_Mime::mimeIdArithmetic($version_id, 'next');

        /* Initialize inline data. */
        self::$_inlinecache[$base_id] = array(
            $base_id => array(
                'data' => '',
                'status' => array(
                    array(
                        'icon' => Horde::img('mime/encryption.png', 'PGP'),
                        'text' => array()
                    )
                ),
                'type' => 'text/html; charset=' . NLS::getCharset()
            ),
            $version_id => null,
            $data_id => null
        );
        $status = &self::$_inlinecache[$base_id][$base_id]['status'][0]['text'];

        /* Is PGP active? */
        if (empty($GLOBALS['conf']['utils']['gnupg']) ||
            !$GLOBALS['prefs']->getValue('use_pgp')) {
            $status[] = _("The message below has been encrypted with PGP, however, PGP support is disabled so the message cannot be decrypted.");
            return null;
        }

        if (empty($this->_imppgp)) {
            $this->_imppgp = &Horde_Crypt::singleton(array('imp', 'pgp'));
        }

        /* PGP version information appears in the first MIME subpart. We
         * don't currently need to do anything with this information. The
         * encrypted data appears in the second MIME subpart. */
        $encrypted_part = $this->_params['contents']->getMIMEPart($data_id);
        $encrypted_data = $encrypted_part->getContents();

        /* Check if this a symmetrically encrypted message. */
        $symmetric = $this->_imppgp->encryptedSymmetrically($encrypted_data);
        if ($symmetric && !$this->_imppgp->getSymmetricPassphrase()) {
            if (isset($_SESSION['imp']['viewmode']) &&
                ($_SESSION['imp']['viewmode'] == 'imp')) {
                // TODO: Fix to work with DIMP
                /* Ask for the correct passphrase if this is encrypted
                 * symmetrically. */
                $status[] = Horde::link('#', _("The message below has been encrypted with PGP. You must enter the passphrase that was used to encrypt this message."), null, null, $this->_imppgp->getJSOpenWinCode('open_symmetric_passphrase_dialog') . ' return false;') . _("You must enter the passphrase that was used to encrypt this message.") . '</a>';
            }
            return null;
        }

        /* Check if this is a literal compressed message. */
        $info = $this->_imppgp->pgpPacketInformation($encrypted_data);
        $literal = !empty($info['literal']);

        if (!$literal && !$symmetric) {
            if (!$this->_imppgp->getPersonalPrivateKey()) {
                /* Output if there is no personal private key to decrypt
                 * with. */
                $status[] = _("The message below has been encrypted with PGP, however, no personal private key exists so the message cannot be decrypted.");
                return null;
            } elseif (!$this->_imppgp->getPassphrase() &&
                      ($_SESSION['imp']['viewmode']) &&
                      ($_SESSION['imp']['viewmode'] == 'imp')) {
                // TODO: Fix to work with DIMP
                /* Ask for the private key's passphrase if this is encrypted
                 * asymmetrically. */
                $status[] = Horde::link('#', _("The message below has been encrypted with PGP. You must enter the passphrase for your PGP private key before it can be decrypted."), null, null, $this->_imppgp->getJSOpenWinCode('open_passphrase_dialog') . ' return false;') . _("You must enter the passphrase for your PGP private key to view this message.") . '</a>';
                return null;
            }
        }

        $status[] = $literal ? _("The message below has been compressed with PGP.") : _("The message below has been encrypted with PGP.");

        $decrypted_data = $this->_imppgp->decryptMessage($encrypted_data, $symmetric, !$literal);
        if (is_a($decrypted_data, 'PEAR_Error')) {
            $status[] = _("The message below does not appear to be a valid PGP encrypted message. Error: ") . $decrypted_data->getMessage();
            return null;
        }

        unset(self::$_inlinecache[$base_id][$data_id]);
        $this->_imppgp->unsetSymmetricPassphrase();

        return array($data_id => Horde_Mime_Message::parseMessage($decrypted_data->message, array('mimepart' => true)));
    }

    /**
     * Generates output for 'application/pgp-keys' MIME_Parts.
     *
     * @return string  The HTML output.
     */
    protected function _outputPGPKey()
    {
        /* Initialize status message. */
        $status = array(
            'icon' => Horde::img('mime/encryption.png', 'PGP'),
            'text' => array(
                _("A PGP Public Key was attached to the message.")
            )
        );

        if ($GLOBALS['prefs']->getValue('use_pgp') &&
            $GLOBALS['prefs']->getValue('add_source') &&
            $GLOBALS['registry']->hasMethod('contacts/addField')) {
            $status['text'][] = Horde::link('#', '', '', '', $this->_imppgp->savePublicKeyURL($mime) . 'return false;') . _("[Save the key to your Address book]") . '</a>';
        }
        $status['text'][] = $this->_params['contents']->linkViewJS($this->_mimepart, 'view_attach', _("View the raw text of the Public Key."), array('params' => array('mode' => IMP_Contents::RENDER_INLINE, 'rawpgpkey' => 1)));

        return array(
            $this->_mimepart->getMimeId() => array(
                'data' => '<span class="fixed">' . nl2br(str_replace(' ', '&nbsp;', $this->_imppgp->pgpPrettyKey($this->_mimepart->getContents()))) . '</span>',
                'status' => array($status),
                'type' => 'text/html; charset=' . NLS::getCharset()
            )
        );
    }

    /**
     * Generates HTML output for 'multipart/signed' MIME parts.
     *
     * @return string  The HTML output.
     */
    protected function _outputPGPSigned()
    {
        $partlist = array_keys($this->_mimepart->contentTypeMap());
        $base_id = reset($partlist);
        $signed_id = next($partlist);
        $sig_id = Horde_Mime::mimeIdArithmetic($signed_id, 'next');

        $ret = array(
            $base_id => array(
                'data' => '',
                'status' => array(
                    array(
                        'icon' => Horde::img('mime/encryption.png', 'PGP'),
                        'text' => array()
                    )
                ),
                'type' => 'text/html; charset=' . NLS::getCharset()
            ),
            $sig_id => null
        );
        $status = &$ret[$base_id]['status'][0]['text'];

        if (!$GLOBALS['prefs']->getValue('use_pgp') ||
            empty($GLOBALS['conf']['utils']['gnupg'])) {
            /* If PGP not active, hide signature data and output status
             * information. */
            $status[] = _("The message below has been digitally signed with PGP, but the signature cannot be verified.");
            return $ret;
        }

        $status[] = _("The message below has been digitally signed with PGP.");

        if ($GLOBALS['prefs']->getValue('pgp_verify') ||
            Util::getFormData('pgp_verify_msg')) {
            $signed_data = Horde_Imap_Client::removeBareNewlines($this->_params['contents']->getBodyPart($signed_id, array('mimeheaders' => true)));
            $sig_part = $this->_params['contents']->getMIMEPart($sig_id);

            /* Check for the 'x-imp-pgp-signature' param. This is set by the
             * plain driver when parsing PGP armor text. */
            if ($sig_part->getContentTypeParameter('x-imp-pgp-signature')) {
                $sig_result = $this->_imppgp->verifySignature($signed_data, $this->_address);
            } else {
                $sig_result = $this->_imppgp->verifySignature($signed_data, $this->_address, $sig_part->getContents());
            }

            $graphicsdir = $GLOBALS['registry']->getImageDir('horde');

            if (is_a($sig_result, 'PEAR_Error')) {
                $icon = Horde::img('alerts/error.png', _("Error"), null, $graphicsdir);
                $sig_result = $sig_result->getMessage();
            } else {
                $icon = Horde::img('alerts/success.png', _("Success"), null, $graphicsdir);
                if (empty($sig_result)) {
                   $sig_result = _("The message below has been verified.");
                }
            }

            require_once 'Horde/Text/Filter.php';
            $ret[$base_id]['status'][] = array(
                'icon' => $icon,
                'text' => array(
                    Text_Filter::filter($sig_result, 'text2html', array('parselevel' => TEXT_HTML_NOHTML))
                )
            );
        } elseif (isset($_SESSION['imp']['viewmode']) &&
                  ($_SESSION['imp']['viewmode'] == 'imp')) {
            // TODO: Fix to work with DIMP
            $status[] = Horde::link(Util::addParameter(Horde::selfUrl(true), array('pgp_verify_msg' => 1))) . _("Click HERE to verify the message.") . '</a>';
        }

        return $ret;
    }

    /**
     * Generates HTML output for 'multipart/encrypted' MIME parts.
     *
     * @return string  The HTML output.
     */
    protected function _outputPGPEncrypted()
    {
        $id = $this->_mimepart->getMimeId();
        return isset(self::$_inlinecache[$id])
            ? self::$_inlinecache[$id]
            : array();
    }
}
