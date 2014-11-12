<?php

/**
 * @package AdvancedShipping
 * @class   advancedShippingHandler
 * @author  Serhey Dolgushev <dolgushev.serhey@gmail.com>
 * @date    27 Nov 2012
 * */
class advancedShippingHandler {

    protected static $optionsMapping = array(
        'rules'                 => array(
            'Description'        => 'description',
            'RequiredOrderTotal' => 'required_order_total',
            'DefaultCost'        => 'default_cost',
            'PerItemCost'        => 'per_item_cost',
            'MinCost'            => 'min_cost',
            'MaxCost'            => 'max_cost'
        ),
        'address_extra_charges' => array(
            'Keywords'       => 'keywords',
            'AdditionalCost' => 'additional_cost',
            'Description'    => 'description'
        )
    );
    protected $rules                 = array();
    protected $addressExtraCharges   = array();

    public function __construct() {
        $ini = eZINI::instance( 'shipping.ini' );
        if( $ini->hasVariable( 'Shipping', 'Rules' ) ) {
            $rules = (array) $ini->variable( 'Shipping', 'Rules' );
            foreach( $rules as $ruleName ) {
                $ruleIniGroup = 'Rule_' . $ruleName;
                if( $ini->hasSection( $ruleIniGroup ) === false ) {
                    continue;
                }

                $ruleOptions = array();
                foreach( self::$optionsMapping['rules'] as $iniVariable => $option ) {
                    if( $ini->hasVariable( $ruleIniGroup, $iniVariable ) ) {
                        $ruleOptions[$option] = $ini->variable( $ruleIniGroup, $iniVariable );
                    }
                }

                if(
                    isset( $ruleOptions['required_order_total'] ) === false || (
                    isset( $ruleOptions['default_cost'] ) === false && isset( $ruleOptions['per_item_cost'] ) === false
                    )
                ) {
                    continue;
                }

                $this->rules[$ruleName] = $ruleOptions;
            }
        }

        if( $ini->hasVariable( 'Shipping', 'AddressExtraCharges' ) ) {
            $addressExtraCharges = (array) $ini->variable( 'Shipping', 'AddressExtraCharges' );
            foreach( $addressExtraCharges as $extraChargeName ) {
                $extraChargeIniGroup = 'AddressExtraCharge_' . $extraChargeName;
                if( $ini->hasSection( $extraChargeIniGroup ) === false ) {
                    continue;
                }

                $extraChargeOptions = array();
                foreach( self::$optionsMapping['address_extra_charges'] as $iniVariable => $option ) {
                    if( $ini->hasVariable( $extraChargeIniGroup, $iniVariable ) ) {
                        $extraChargeOptions[$option] = $ini->variable( $extraChargeIniGroup, $iniVariable );
                    }
                }

                if(
                    isset( $extraChargeOptions['keywords'] ) === false ||
                    count( $extraChargeOptions['keywords'] ) === 0 ||
                    isset( $extraChargeOptions['additional_cost'] ) === false
                ) {
                    continue;
                }

                $this->addressExtraCharges[$extraChargeName] = $extraChargeOptions;
            }
        }
    }

    public function getShippingInfo( $productCollectionID ) {
        $rule = $this->getCurrentRule();
        if( $rule === false ) {
            return null;
        }

        $cost = 0;
        if( isset( $rule['per_item_cost'] ) ) {
            $itemsCount = 0;
            $items      = array();
            $products   = eZProductCollection::fetch( $productCollectionID );
            if( $products instanceof eZProductCollection ) {
                $items = eZProductCollection::fetch( $productCollectionID )->itemList();
            }
            foreach( $items as $item ) {
                $object = $item->attribute( 'contentobject' );
                if( $object instanceof eZContentObject ) {
                    $dataMap = $object->attribute( 'data_map' );
                    if( isset( $dataMap['free_shipping'] ) && (bool) $dataMap['free_shipping']->attribute( 'content' ) ) {
                        continue;
                    }
                }

                $itemsCount += $item->attribute( 'item_count' );
            }
            $cost = $itemsCount * (float) $rule['per_item_cost'];
        } else {
            $cost = (float) $rule['default_cost'];
        }

        if( isset( $rule['min_cost'] ) ) {
            $cost = max( $cost, (float) $rule['min_cost'] );
        }
        if( isset( $rule['max_cost'] ) ) {
            $cost = min( $cost, (float) $rule['max_cost'] );
        }

        $VAT   = 0;
        $items = eZProductCollection::fetch( $productCollectionID )->itemList();
        if( count( $items ) > 0 ) {
            $VAT = $items[0]->attribute( 'vat_value' );
        }

        $shippingInfo = array(
            'description' => $rule['description'],
            'cost'        => $cost,
            'vat_value'   => $VAT,
            'is_vat_inc'  => 0
        );

        return $this->applyExtraCharges( $shippingInfo );
    }

    public function updateShippingInfo( $productCollectionID ) {
        return null;
    }

    public function purgeShippingInfo( $productCollectionID ) {
        return null;
    }

    protected function getCurrentRule() {
        $requiredOrderTotals = array();
        foreach( $this->rules as $name => $rule ) {
            $requiredOrderTotals[$name] = (float) $rule['required_order_total'];
        }
        arsort( $requiredOrderTotals );

        $total = eZBasket::currentBasket()->attribute( 'total_inc_vat' );
        foreach( $requiredOrderTotals as $ruleName => $requiredOrderTotal ) {
            if( $total >= $requiredOrderTotal ) {
                return $this->rules[$ruleName];
            }
        }
    }

    protected function applyExtraCharges( array $shippingInfo ) {
        $basket = eZBasket::currentBasket();
        $order  = eZOrder::fetch( $basket->attribute( 'order_id' ) );
        if( $order instanceof eZOrder === false ) {
            return false;
        }

        $accountInfo         = $order->attribute( 'account_information' );
        $appliedExtraCharges = array();
        foreach( $this->addressExtraCharges as $extraCharge ) {
            foreach( $extraCharge['keywords'] as $field => $keyword ) {
                if( isset( $accountInfo[$field] ) === false ) {
                    continue;
                }

                if( strpos( mb_strtolower( $accountInfo[$field] ), mb_strtolower( $keyword ) ) !== false ) {
                    $appliedExtraCharges[] = $extraCharge;
                    continue;
                }
            }
        }

        if( count( $appliedExtraCharges ) === 0 ) {
            return $shippingInfo;
        }

        $extraChargesDescription = array();
        foreach( $appliedExtraCharges as $extraCharge ) {
            $shippingInfo['cost'] += $extraCharge['additional_cost'];
            $extraChargesDescription[] = $extraCharge['description'];
        }
        $shippingInfo['description'] .= '(' . implode( ', ', $extraChargesDescription ) . ')';
        return $shippingInfo;
    }

}
