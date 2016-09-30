<?php

class Hibrido_Trustvox_OrdersController extends Mage_Core_Controller_Front_Action {

    private $json;

    private function helper()
    {
        return Mage::helper('hibridotrustvox');
    }

    public function indexAction()
    {
        $this->getResponse()->clearHeaders()->setHeader('Content-type', 'application/json', true);

        if ($this->getRequest()->getHeader('trustvox-token') == $this->helper()->getToken()) {
            $counter = 0;
            $sent = 0;
            $clientArray = array();
            $productArray = array();

            if($this->getRequest()->getHeader('date-period') && $this->getRequest()->getHeader('date-period') >= 1){
                $period = intval($this->getRequest()->getHeader('date-period'));
            }else{
                $period = 15;
            }

            $lastSync = $this->getRequest()->getHeader('last-sync');
            if($lastSync != '' && $lastSync >= 1){
                $period = strtotime(date('Y-m-d H:i:s')) - strtotime($lastSync);
                $period = floor($period / (60 * 60 * 24));
            }

            $orders = $this->helper()->getOrdersByLastDays($period);

            $this->json = array();

            Mage::getSingleton('core/resource_iterator')->walk(
                $orders->getSelect(),
                array(array($this, 'processOrderInfo'))
            );

            return $this->getResponse()->setBody(json_encode($this->json));
        } else {
            $jsonArray = array(
                'error' => true,
                'message' => 'not authorized',
            );
            $this->getResponse()->setBody(json_encode($jsonArray));
        }
    }

    public function processOrderInfo($iterargs)
    {
        $product_resource = Mage::getResourceSingleton('catalog/product');

        $clientArray = $this->helper()->mountClientInfoToSend($iterargs['row']['customer_firstname'], $iterargs['row']['customer_lastname'], $iterargs['row']['customer_email']);

        $enabled = $this->helper()->checkStoreIdEnabled();
        $productArray = array();

        $items = Mage::getResourceModel('sales/order_item_collection')->setOrderFilter($iterargs['row']['entity_id']);

        foreach ($items as $item) {
            $_product = Mage::getModel('catalog/product');

            if ($item->getProductType() == 'simple') {
                $parents = Mage::getResourceSingleton('catalog/product_type_configurable')->getParentIdsByChild($item->getProductId());
                if(count($parents) >= 1){
                    $productId = $parents[0];
                }else{
                    $productId = $item->getProductId();
                }
            }else if($item->getProductType() == 'grouped'){
                $parents = Mage::getModel('catalog/product_type_grouped')->getParentIdsByChild($item->getProductId());
                if(count($parents) >= 1){
                    $productId = $parents[0];
                }else{
                    $productId = $item->getProductId();
                }
            }else{
                $productId = $item->getProductId();
            }

            if ($item->getParentItemId()) {
                $parent_product_type = Mage::getModel('sales/order_item')->load($item->getParentItemId())->getProductType();
                if ($parent_product_type == Mage_Catalog_Model_Product_Type::TYPE_BUNDLE) {
                    $productId = $item->getParentItemId();
                }
            }

            $image = $product_resource->getAttributeRawValue($productId, 'image', Mage::app()->getStore());
            $product_url = Mage::getUrl() . $product_resource->getAttributeRawValue($productId, 'url_path', Mage::app()->getStore());

            if($item->getId()){
                $productArray[$item->getId()] = array(
                    'name' => $item->getName(),
                    'id' => $item->getId(),
                    'price' => $item->getPrice(),
                    'url' => $product_url,
                    'type' => $item->getProductType(),
                    'photos_urls' => array(Mage::getModel('catalog/product_media_config')->getMediaUrl($image)),
                );
            }
        }

        $shippingDate = '';
        $shipments = Mage::getResourceModel('sales/order_shipment_collection')->setOrderFilter($iterargs['row']['entity_id']);

        foreach($shipments as $shipment){
            $shippingDate = $shipment->getCreatedAt();
        }

        if(!$shippingDate || $shippingDate == ''){
            $shippingDate = $iterargs['row']['created_at'];
        }

        array_push($this->json, $this->helper()->forJSON($iterargs['row']['entity_id'], $shippingDate, $clientArray, $productArray));

    }

}
