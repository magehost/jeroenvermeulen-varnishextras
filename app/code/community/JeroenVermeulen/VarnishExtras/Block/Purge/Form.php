<?php

class JeroenVermeulen_VarnishExtras_Block_Purge_Form extends Mage_Adminhtml_Block_Template
{
    public function canFlushWildcard()
    {
        return Mage::getSingleton('admin/session')->isAllowed('system/jv_varnish_purge/wildcard');
    }

    /**
     * @return string
     */
    protected function _toHtml()
    {
        return parent::_toHtml();
    }
}