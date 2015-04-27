<?php 
/***
 * A help class to translate huijiprefix and the actual site name.
 */
class HuijiPrefix{
	public static function prefixToSiteName( $prefix ){
		$dbr = wfGetDB( DB_SLAVE );
		$s = $dbr->selectRow(
			'domain',
			array( 'domain_id', 'domain_name' ),
			array(
				'domain_prefix' => $prefix,
			),
			__METHOD__
		);

		if ( $s !== false ) {
			return $s->domain_name;
		}else{
			return $prefix;
		}
	}
	public static function prefixToUrl( $prefix ){
		return 'http://'.$prefix.'.huiji.wiki/';
	}
}