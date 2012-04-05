<?php
/**
 * DIMP Base Class - provides dynamic view functions.
 *
 * Copyright 2005-2012 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.horde.org/licenses/gpl.
 *
 * @author   Michael Slusarz <slusarz@horde.org>
 * @category Horde
 * @license  http://www.horde.org/licenses/gpl GPL
 * @package  IMP
 */
class IMP_Dimp
{
    /**
     * Output a dimp-style action (menubar) link.
     *
     * @param array $params  A list of parameters:
     *   - app: (string) The application to load the icon from.
     *   - class: (string) The CSS classname to use for the link.
     *   - icon: (string) The icon CSS classname.
     *   - id: (string) The DOM ID of the link.
     *   - title: (string) The title string.
     *
     * @return string  An HTML link to $url.
     */
    static public function actionButton($params = array())
    {
        return Horde::link(
            '',
            '',
            empty($params['class']) ? '' : $params['class'],
            '',
            '',
            '',
            Horde::getAccessKey($params['title']),
           empty($params['id']) ? array() : array('id' => $params['id']),
           true
       ) . (empty($params['icon'])
            ? ''
            : '<span class="iconImg dimpaction' . $params['icon'] . '"></span>').
           $params['title'] . '</a>';
    }

    /**
     * Build data structure needed by DimpCore javascript to display message
     * log information.
     *
     * @var string $msg_id  The Message-ID header of the message.
     *
     * @return array  An array of information that can be parsed by
     *                DimpCore.updateInfoList().
     */
    static public function getMsgLogInfo($msg_id)
    {
        $ret = array();

        foreach (IMP_Maillog::parseLog($msg_id) as $val) {
            $ret[] = array_map('htmlspecialchars', array(
                'm' => $val['msg'],
                't' => $val['action']
            ));
        }

        return $ret;
    }

    /**
     * Parse an address list created by the dynamic view JS code.
     *
     * @param string $json  JSON input code.
     *
     * @return Horde_Mail_Rfc822_List  A list of addresses.
     */
    static public function parseDimpAddressList($json)
    {
        $data = Horde_Serialize::unserialize($json, Horde_Serialize::JSON);
        $out = new Horde_Mail_Rfc822_List();

        if (isset($data->g)) {
            $addrs = $data->a;
            $ob = new Horde_Mail_Rfc822_Group($data->g);
            $ob_add = $ob->addresses;
            $out->add($ob);
        } else {
            $addrs = array($data);
            $ob_add = $out;
        }

        foreach ($addrs as $jval) {
            $addr_ob = new Horde_Mail_Rfc822_Address($jval->b);
            if (isset($jval->p)) {
                $addr_ob->personal = $jval->p;
            }
            $ob_add->add($addr_ob);
        }

        return $out;
    }

}
