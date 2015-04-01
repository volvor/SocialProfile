<?php
/**
 * AJAX functions used by UserSiteFollow extension.
 */

$wgAjaxExportList[] = 'wfUserUserFollowsResponse';
$wgAjaxExportList[] = 'wfUserUserUnfollowsResponse';
function wfUserUserFollowsResponse( $follower, $followee ) {
	global $wgUser;
	$out = 'fail';

	$uuf = new UserUserFollow();
	if ( $follower === $wgUser->getName() && $followee !== $follower){
		if ($uuf->addUserUserFollow($wgUser, User::newFromName($followee)) !== false){
			$out = '已关注';
		}
	}
		 //TODO: use wfMessage instead of hard code
	return $out;
}
function wfUserUserUnfollowsResponse( $follower, $followee ) {
	global $wgUser;
	$out = 'fail';

	$uuf = new UserUserFollow();
	if ( $follower === $wgUser->getName() && $followee !== $follower){
		if ($uuf->deleteUserUserFollow($wgUser, User::newFromName($followee))){
			$out = '关注'.$followee;
		}
		 //TODO: use wfMessage instead of hard code
	}
	return $out;
}