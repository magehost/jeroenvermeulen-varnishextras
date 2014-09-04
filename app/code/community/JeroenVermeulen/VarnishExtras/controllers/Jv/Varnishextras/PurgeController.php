<?php

class JeroenVermeulen_VarnishExtras_Jv_Varnishextras_PurgeController extends Mage_Adminhtml_Controller_Action
{

    // URL:  http://[MAGROOT]/admin/jv_varnishextras_purge/key/###########/
    // URL:  http://[MAGROOT]/admin/jv_varnishextras_purge/index/key/###########/
    // If "storecode in url" is enabled there will be "/admin/admin" before "/jv_varnishextras_purge"
    public function indexAction()
    {
        $this->loadLayout();
        $this->renderLayout();
        /**
         * Layout will be chosen by @see Mage_Core_Controller_Varien_Action::addActionLayoutHandles
         * Layout file:        /app/design/adminhtml/default/default/layout/JeroenVermeulen/VarnishExtras.xml
         * Item in that file:  adminhtml_jv_varnishextras_purge_index
         */
        // Enable to debug layout XML:
        // header( 'Content-Type: text/xml' ); echo $this->getLayout()->getXmlString(); exit;
    }

    // URL:  http://[MAGROOT]/admin/jv_varnishextras_purge/post/key/###########/
    // If "storecode in url" is enabled there will be "/admin/admin" before "/jv_varnishextras_purge"
    public function postAction()
    {
        $session     = Mage::getSingleton( 'core/session' );
        $readAdapter = Mage::getSingleton('core/resource')->getConnection('core_read');
        $post        = $this->getRequest()->getPost();
        $url         = trim( $post['purgeform']['url'] );
        $method      = empty( $post['purgeform']['method'] ) ? 'exact' : $post['purgeform']['method'];
        if ( !preg_match('|https?://|',$url) ) {
            $session->addError( $this->__('Please use a full URL, start with "http://"') );
        }
        $urlParts = parse_url( $url );
        if ( false === $urlParts || empty( $urlParts['host'] ) ) {
            $session->addError( $this->__('Invalid URL: <b>%s</b>.',$url) );
            $session->setData( 'JeroenVermeulen_VarnishExtras_PurgeURL', '' );
        } else {
            Mage::log( sprintf( 'JeroenVermeulen_VarnishExtras_PurgeController %s "%s"',
                                $method,
                                $url ),
                       Zend_Log::INFO,
                       'turpentine.log' );
            $pathMatch = $urlParts['path'];
            $pathMatch = rtrim( $pathMatch, '/' );
            $pathMatch = '^' . $pathMatch;
            if ( 'wildcard' == $method ) {
                $pathMatch .= '.*';
            } else {
                $pathMatch .= '/?($|\?|#)';
            }
            $hostMatch = $urlParts['host'];
            if ( !empty( $urlParts['port'] ) ) {
                $hostMatch .= ':' . $urlParts['port'];
            }
            $sockets = Mage::helper( 'turpentine/varnish' )->getSockets();
            if ( empty( $sockets ) ) {
                $msg = $this->__( 'No Varnish servers found in Turpentine configuration.' );
                $session->addError( $msg );
                Mage::log( $msg, Zend_Log::WARN, 'turpentine.log' );
            }
            else {
                $result = array();
                /** @var Nexcessnet_Turpentine_Model_Varnish_Admin_Socket $socket */
                foreach( $sockets as $socket ) {
                    $socketName = $socket->getConnectionString();
                    try {
                        $sockReturn = $socket->ban( 'obj.http.X-Varnish-Host', '==', $hostMatch, '&&', 'obj.http.X-Varnish-URL', '~', $pathMatch );
                        if ( !empty($sockReturn['code'])
                             and ( 200 <= $sockReturn['code'] )
                             and ( 300 > $sockReturn['code'] ) ) {
                            $result[$socketName] = true;
                        } else {
                            $socketReturnCode = empty($sockReturn['code']) ? '[EMPTY]' : $sockReturn['code'];
                            $socketReturnMsg  = empty($sockReturn['text']) ? '' : $sockReturn['text'];
                            $result[$socketName] = sprintf( 'ERROR code %s: %s', $socketReturnCode, $socketReturnMsg );
                        }
                    } catch( Exception $e ) {
                        $result[$socketName] = $e->getMessage();
                        continue;
                    }
                }
                foreach( $result as $name => $value ) {
                    if( $value === true ) {
                        if ( 'wildcard' == $method ) {
                            $msg = $this->__( 'All URLs starting with %s have been purged on %s.',
                                              '<span style="color:#FF6600">'.$url.'</span>',
                                              $name );
                        } else {
                            $sLink = sprintf( '<a href="%s" target="_blank">%s</a>', $url, $url );
                            $msg = $this->__( 'The URL %s has been purged on %s.', $sLink, $name );
                        }
                        Mage::log( $msg, Zend_Log::INFO, 'turpentine.log' );
                        $session->addSuccess( $msg );
                    } else {
                        $msg = $this->__( 'Error purging URL %s on %s: %s', $url, $name, $value );
                        Mage::log( $msg, Zend_Log::ERR, 'turpentine.log' );
                        $session->addError( $msg );
                    }
                }
                $cronHelper = Mage::helper( 'turpentine/cron' );
                if( $cronHelper->getCrawlerEnabled() ) {
                    if ( 'exact' == $method ) {
                        $cronHelper->addUrlToCrawlerQueue( $url );
                    } elseif ( 'wildcard' == $method ) {
                        $allStores = Mage::app()->getStores();
                        $matchingStoreIds = array();
                        foreach ($allStores as $store) {
                            $baseUrl = Mage::getStoreConfig( 'web/unsecure/base_url', $store );
                            $baseUrl = rtrim( $baseUrl, '/' );
                            if ( 0 === strpos( $url, $baseUrl ) ) {
                                $matchingStoreIds[ $store->getId() ] = $baseUrl;
                            }
                        }
                        $flushQueue = array();
                        foreach ($matchingStoreIds as $storeId => $baseUrl ) {
                            $matchUrl = $url;
                            $matchUrl = str_replace( $baseUrl, '', $matchUrl );
                            $matchUrl = trim( $matchUrl, '/' );
                            $table = Mage::getSingleton('core/resource')->getTableName('core_url_rewrite');
                            $select = $readAdapter->select()->from( $table, array('request_path') );
                            $select->where( 'request_path LIKE ?', $matchUrl . '%' );
                            $select->where( 'store_id = ?', $storeId );
                            $select->limit( 1000 );
                            $flushUrls = $select->query()->fetchAll( Zend_Db::FETCH_COLUMN, 0 );
                            foreach ( $flushUrls as $flushUrl ) {
                                $flushQueue[] = $baseUrl . '/' . $flushUrl;
                            }
                        }
                        $cronHelper->addUrlsToCrawlerQueue( array_unique( $flushQueue ) );
                    }
                }
            }
            $session->setData( 'JeroenVermeulen_VarnishExtras_PurgeURL', $url );
            $session->setData( 'JeroenVermeulen_VarnishExtras_PurgeMethod', $method );
        }
        $this->_redirect( '*/*/' ); // Redirect to index action
    }

}
