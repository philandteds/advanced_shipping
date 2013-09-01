<?php
/**
 * @package AdvancedShipping
 * @class   countryShippingHandler
 * @author  Serhey Dolgushev <dolgushev.serhey@gmail.com>
 * @date    31 Aug 2013
 **/

class countryShippingHandler
{
	private static $optionsMapping = array(
		'Description'        => 'description',
		'Countries'          => 'countries',
		'RequiredOrderTotal' => 'required_order_total',
		'ShippingCost'       => 'shipping_cost'
	);
	private $rules = array();

	public function __construct() {
		$ini = eZINI::instance( 'shipping.ini' );
		if( $ini->hasVariable( 'Shipping', 'CountryRules' ) ) {
			$rules = (array) $ini->variable( 'Shipping', 'CountryRules' );
			foreach( $rules as $ruleName ) {
				$ruleIniGroup = 'CountryRule_' . $ruleName;
				if( $ini->hasSection( $ruleIniGroup ) === false ) {
					continue;
				}

				$ruleOptions = array();
				foreach( self::$optionsMapping as $iniVariable => $option ) {
					if( $ini->hasVariable( $ruleIniGroup, $iniVariable ) ) {
						$ruleOptions[ $option ] = $ini->variable( $ruleIniGroup, $iniVariable );
					}
				}

				if(
					isset( $ruleOptions['required_order_total'] ) === false
					|| isset( $ruleOptions['shipping_cost'] ) === false
				) {
					continue;
				}

				$ruleOptions['countries'] = explode( ',', $ruleOptions['countries'] );

				$this->rules[ $ruleName ] = $ruleOptions;
			}
		}
	}

	public function getShippingInfo( $productCollectionID ) {
		$rule = $this->getCurrentRule();
		if( $rule === false ) {
			return null;
		}

		$VAT   = 0;
		$items = eZProductCollection::fetch( $productCollectionID )->itemList();
		if( count( $items ) > 0 ) {
			$VAT = $items[0]->attribute( 'vat_value' );
		}

		return array(
			'description' => $rule['description'],
			'cost'        => $rule['shipping_cost'],
			'vat_value'   => $VAT,
			'is_vat_inc'  => 0
		);
	}

	public function updateShippingInfo( $productCollectionID ) {
		return null;
	}

	public function purgeShippingInfo( $productCollectionID ) {
		return null;
	}

	private function getCurrentRule() {
		$basket = eZBasket::currentBasket();
		$order  = eZOrder::fetch( $basket->attribute( 'order_id' ) );
		if( $order instanceof eZOrder === false ) {
			return false;
		}
		
		$accountInfo = $order->attribute( 'account_information' );
		if(
			isset( $accountInfo['s_country'] ) === false
			|| strlen( $accountInfo['s_country'] ) === 0
		) {
			return false;
		}

		$requiredOrderTotals = array();
		foreach( $this->rules as $name => $rule ) {
			if( in_array( $accountInfo['s_country'], $rule['countries'] ) === false ) {
				continue;
			}

			$requiredOrderTotals[ $name ] = (float) $rule['required_order_total'];
		}
		arsort( $requiredOrderTotals );

		$total = $basket->attribute( 'total_inc_vat' );
		foreach( $requiredOrderTotals as $ruleName => $requiredOrderTotal ) {
			if( $total >= $requiredOrderTotal ) {
				return $this->rules[ $ruleName ];
			}
		}
	}
}
