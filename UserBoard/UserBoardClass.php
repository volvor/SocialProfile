<?php
/**
 * Functions for managing user board data
 */
class UserBoard {

	/**
	 * Constructor
	 */
	public function __construct() {}

	/**
	 * Sends a user board message to another user.
	 * Performs the insertion to user_board table, sends e-mail notification
	 * (if appliable), and increases social statistics as appropriate.
	 *
	 * @param $user_id_from Integer: user ID of the sender
	 * @param $user_name_from Mixed: user name of the sender
	 * @param $user_id_to Integer: user ID of the reciever
	 * @param $user_name_to Mixed: user name of the reciever
	 * @param $message Mixed: message text
	 * @param $message_type Integer: 0 for public message
	 * @return Integer: the inserted value of ub_id row
	 */
	public function sendBoardMessage( $user_id_from, $user_name_from, $user_id_to, $user_name_to, $message, $message_type = 0 ) {		
		// convert '@' to wiki link;
		$message = HuijiFunctions::preprocessText($message);

		$dbw = wfGetDB( DB_MASTER );

		$user_name_from = stripslashes( $user_name_from );
		$user_name_to = stripslashes( $user_name_to );

		$dbw->insert(
			'user_board',
			array(
				'ub_user_id_from' => $user_id_from,
				'ub_user_name_from' => $user_name_from,
				'ub_user_id' => $user_id_to,
				'ub_user_name' => $user_name_to,
				'ub_message' => $message,
				'ub_type' => $message_type,
				'ub_date' => date( 'Y-m-d H:i:s' ),
			),
			__METHOD__
		);

		// Send e-mail notification (if user is not writing on own board)
		if ( $user_id_from != $user_id_to ) {
			$this->sendBoardNotificationEmail( $user_id_to, $user_name_from, $message );
			$this->incNewMessageCount( $user_id_to );
		}
		$mentionedUsers = HuijiFunctions::getMentionedUsers($message);
		if ( count( $mentionedUsers ) && $message_type == 0 ) {
			$this->sendMentionedNotification($user_id_from, $user_name_from, $user_id_to, $user_name_to, $message, $mentionedUsers);
		}

		$stats = new UserStatsTrack( $user_id_to, $user_name_to );
		if ( $message_type == 0 ) {
			// public message count
			$stats->incStatField( 'user_board_count' );
		} else {
			// private message count
			$stats->incStatField( 'user_board_count_priv' );
		}

		$stats = new UserStatsTrack( $user_id_from, $user_name_from );
		$stats->incStatField( 'user_board_sent' );

		return $dbw->insertId();
	}
	/**
	 * Sends an Echo to mentioned users.
	 *
	 */
	public function sendMentionedNotification($user_id_from, $user_name_from, $user_id_to, $user_name_to, $message, $mentionedUsers){
		$agent = User::newFromId( $user_id_from );
		$board_link = SpecialPage::getTitleFor( 'UserBoard' );
		EchoEvent::create( array(
			'type' => 'board-msg',
			'title' => $board_link,
		    'extra' => array(
		         'board-user-id' => $user_id_to,  
		         'board-user' => $user_name_to,
		         'board-user-conv' => $user_name_from,
		         'mentioned-users' => $mentionedUsers,
		         'board-content' => $message,
		     ),
			'agent' => $agent,
		) );
	}

	/**
	 * Sends an <s>email</s>/echo to a user if someone wrote on their board.
	 *
	 * @param $user_id_to Integer: user ID of the reciever
	 * @param $user_from Mixed: the user name of the person who wrote the board message
	 */
	public function sendBoardNotificationEmail( $user_id_to, $user_from, $message ) {
		$user = User::newFromId( $user_id_to );
		$user->loadFromId();

		$agent = User::newFromName($user_from);

		// send an echo notification
		$board_link = SpecialPage::getTitleFor( 'UserBoard' );
		$username = $user->getName();
		EchoEvent::create( array(
		     'type' => 'board-msg',
		     'extra' => array(
		         'board-user-id' => $user_id_to,  
		         'board-user' => $username,
		         'board-user-conv' => $user_from,
		         'board-content' => $message,
		     ),
		     'agent' => $agent,
		     'title' => $board_link,
		) );

		// // Send email if user's email is confirmed and s/he's opted in to recieving social notifications
		// if ( $user->isEmailConfirmed() && $user->getIntOption( 'notifymessage', 1 ) ) {
		// 	$board_link = SpecialPage::getTitleFor( 'UserBoard' );
		// 	$update_profile_link = SpecialPage::getTitleFor( 'UpdateProfile' );
		// 	$subject = wfMessage( 'message_received_subject', $user_from )->parse();
		// 	$body = wfMessage( 'message_received_body',
		// 		$user->getName(),
		// 		$user_from,
		// 		htmlspecialchars( $board_link->getFullURL() ),
		// 		htmlspecialchars( $update_profile_link->getFullURL() )
		// 	)->text();
		// 	// The email contains HTML, so actually send it out as such, too.
		// 	// That's why this no longer uses User::sendMail().
		// 	// @see https://bugzilla.wikimedia.org/show_bug.cgi?id=68045
		// 	global $wgPasswordSender;
		// 	$sender = new MailAddress( $wgPasswordSender,
		// 		wfMessage( 'emailsender' )->inContentLanguage()->text() );
		// 	$to = new MailAddress( $user );
		// 	UserMailer::send( $to, $sender, $subject, $body, null, 'text/html; charset=UTF-8' );

		// }
	}

	/**
	 * Increase the amount of new messages for $user_id
	 *
	 * @param $user_id Integer: user ID for the user whose message count we're
	 *							going to increase.
	 */
	public function incNewMessageCount( $user_id ) {
		global $wgMemc;
		$key = wfForeignMemcKey( 'huiji', '', 'user', 'newboardmessage', $user_id );
		$wgMemc->incr( $key );
	}

	/**
	 * Clear the new board messages counter for the user with ID = $user_id.
	 * This is done by setting the value of the memcached key to 0.
	 *
	 * @param $user_id Integer: user ID for the user whose message count we're
	 *							going to clear.
	 */
	static function clearNewMessageCount( $user_id ) {
		global $wgMemc;
		$key = wfForeignMemcKey( 'huiji', '', 'user', 'newboardmessage', $user_id );
		$wgMemc->set( $key, 0 );
	}

	/**
	 * Get the amount of new board messages for the user with ID = $user_id
	 * from memcached. If successful, returns the amount of new messages.
	 *
	 * @param $user_id Integer: user ID for the user whose messages we're going
	 *							to fetch.
	 * @return Integer: amount of new messages
	 */
	static function getNewMessageCountCache( $user_id ) {
		global $wgMemc;
		$key = wfForeignMemcKey( 'huiji', '', 'user', 'newboardmessage', $user_id );
		$data = $wgMemc->get( $key );
		if ( $data != '' ) {
			wfDebug( "Got new message count of $data for id $user_id from cache\n" );
			return $data;
		}
	}

	/**
	 * Get the amount of new board messages for the user with ID = $user_id
	 * from the database.
	 *
	 * @param $user_id Integer: user ID for the user whose messages we're going
	 *							to fetch.
	 * @return Integer: amount of new messages
	 */
	static function getNewMessageCountDB( $user_id ) {
		global $wgMemc;

		wfDebug( "Got new message count for id $user_id from DB\n" );

		$key = wfForeignMemcKey( 'huiji', '', 'user', 'newboardmessage', $user_id );
		$newCount = 0;
		/*
		$dbw = wfGetDB( DB_MASTER );
		$s = $dbw->selectRow(
			'user_board',
			array( 'COUNT(*) AS count' ),
			array( 'ug_user_id_to' => $user_id, 'ug_status' => 1 ),
			__METHOD__
		);
		if ( $s !== false ) {
			$newCount = $s->count;
		}
		*/

		$wgMemc->set( $key, $newCount );

		return $newCount;
	}

	/**
	 * Get the amount of new board messages for the user with ID = $user_id.
	 * First tries cache (memcached) and if that succeeds, returns the cached
	 * data. If that fails, the count is fetched from the database.
	 * UserWelcome.php calls this function.
	 *
	 * @param $user_id Integer: user ID for the user whose messages we're going
	 *							to fetch.
	 * @return Integer: amount of new messages
	 */
	static function getNewMessageCount( $user_id ) {
		$data = self::getNewMessageCountCache( $user_id );

		if ( $data != '' ) {
			$count = $data;
		} else {
			$count = self::getNewMessageCountDB( $user_id );
		}

		return $count;
	}

	/**
	 * Checks if the user with ID number $user_id owns the board message with
	 * the ID number $ub_id.
	 *
	 * @param $user_id Integer: user ID number
	 * @param $ub_id Integer: user board message ID number
	 * @return Boolean: true if user owns the message, otherwise false
	 */
	public function doesUserOwnMessage( $user_id, $ub_id ) {
		$dbr = wfGetDB( DB_SLAVE );
		$s = $dbr->selectRow(
			'user_board',
			array( 'ub_user_id' ),
			array( 'ub_id' => $ub_id ),
			__METHOD__
		);
		if ( $s !== false ) {
			if ( $user_id == $s->ub_user_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Deletes a user board message from the database and decreases social
	 * statistics as appropriate (either 'user_board_count' or
	 * 'user_board_count_priv' is decreased by one).
	 *
	 * @param $ub_id Integer: ID number of the board message that we want to delete
	 */
	public function deleteMessage( $ub_id ) {
		if ( $ub_id ) {
			$dbw = wfGetDB( DB_MASTER );
			$s = $dbw->selectRow(
				'user_board',
				array( 'ub_user_id', 'ub_user_name', 'ub_type' ),
				array( 'ub_id' => $ub_id ),
				__METHOD__
			);
			if ( $s !== false ) {
				$dbw->delete(
					'user_board',
					array( 'ub_id' => $ub_id ),
					__METHOD__
				);

				$stats = new UserStatsTrack( $s->ub_user_id, $s->ub_user_name );
				if ( $s->ub_type == 0 ) {
					$stats->decStatField( 'user_board_count' );
				} else {
					$stats->decStatField( 'user_board_count_priv' );
				}
			}
		}
	}

	/**
	 * Get the user board messages for the user with the ID $user_id.
	 *
	 * @todo FIXME: rewrite this function to be compatible with non-MySQL DBMS
	 * @param $user_id Integer: user ID number
	 * @param $user_id_2 Integer: user ID number of the second user; only used
	 *                            in board-to-board stuff
	 * @param $limit Integer: used to build the LIMIT and OFFSET for the SQL
	 *                        query
	 * @param $page Integer: used to build the LIMIT and OFFSET for the SQL
	 *                       query
	 * @return Array: array of user board messages
	 */
	public function getUserBoardMessages( $user_id, $user_id_2 = 0, $limit = 0, $page = 0 ) {
		global $wgUser, $wgOut, $wgTitle;
		$dbr = wfGetDB( DB_SLAVE );

		if ( $limit > 0 ) {
			$limitvalue = 0;
			if ( $page ) {
				$limitvalue = $page * $limit - ( $limit );
			}
			$limit_sql = " LIMIT {$limitvalue},{$limit} ";
		}

		if ( $user_id_2 ) {
			$user_sql = "( (ub_user_id={$user_id} AND ub_user_id_from={$user_id_2}) OR
					(ub_user_id={$user_id_2} AND ub_user_id_from={$user_id}) )";
			if ( !( $user_id == $wgUser->getID() || $user_id_2 == $wgUser->getID() ) ) {
				$user_sql .= ' AND ub_type = 0 ';
			}
		} else {
			$user_sql = "ub_user_id = {$user_id}";
			if ( $user_id != $wgUser->getID() ) {
				$user_sql .= ' AND ub_type = 0 ';
			}
			if ( $wgUser->isLoggedIn() ) {
				$user_sql .= " OR (ub_user_id={$user_id} AND ub_user_id_from={$wgUser->getID() }) ";
			}
		}

		$sql = "SELECT ub_id, ub_user_id_from, ub_user_name_from, ub_user_id, ub_user_name,
			ub_message,UNIX_TIMESTAMP(ub_date) AS unix_time,ub_type
			FROM {$dbr->tableName( 'user_board' )}
			WHERE {$user_sql}
			ORDER BY ub_id DESC
			{$limit_sql}";
		$res = $dbr->query( $sql, __METHOD__ );

		$messages = array();

		foreach ( $res as $row ) {
			$parser = new Parser();
			$message_text = $parser->parse( $row->ub_message, $wgTitle, $wgOut->parserOptions(), true );
			$message_text = $message_text->getText();

			$messages[] = array(
				'id' => $row->ub_id,
				'timestamp' => ( $row->unix_time ),
				'user_id_from' => $row->ub_user_id_from,
				'user_name_from' => $row->ub_user_name_from,
				'user_id' => $row->ub_user_id,
				'user_name' => $row->ub_user_name,
				'message_text' => $message_text,
				'type' => $row->ub_type
			);
		}

		return $messages;
	}

	/**
	 * Get the amount of board-to-board messages sent between the users whose
	 * IDs are $user_id and $user_id_2.
	 *
	 * @todo FIXME: rewrite this function to be compatible with non-MySQL DBMS
	 * @param $user_id Integer: user ID of the first user
	 * @param $user_id_2 Integer: user ID of the second user
	 * @return Integer: the amount of board-to-board messages
	 */
	public function getUserBoardToBoardCount( $user_id, $user_id_2 ) {
		global $wgUser;

		$dbr = wfGetDB( DB_SLAVE );

		$user_sql = " ( (ub_user_id={$user_id} AND ub_user_id_from={$user_id_2}) OR
					(ub_user_id={$user_id_2} AND ub_user_id_from={$user_id}) )";

		if ( !( $user_id == $wgUser->getID() || $user_id_2 == $wgUser->getID() ) ) {
			$user_sql .= ' AND ub_type = 0 ';
		}
		$sql = "SELECT COUNT(*) AS the_count
			FROM {$dbr->tableName( 'user_board' )}
			WHERE {$user_sql}";

		$res = $dbr->query( $sql, __METHOD__ );
		$row = $dbr->fetchObject( $res );

		if ( $row ) {
			$count = $row->the_count;
		}

		return $count;
	}

	public function displayMessages( $user_id, $user_id_2 = 0, $count = 10, $page = 0 ) {
		global $wgUser, $wgTitle;

		$output = ''; // Prevent E_NOTICE
		$messages = $this->getUserBoardMessages( $user_id, $user_id_2, $count, $page );

		if ( $messages ) {
			foreach ( $messages as $message ) {
				$user = Title::makeTitle( NS_USER, $message['user_name_from'] );
				$avatar = new wAvatar( $message['user_id_from'], 'ml' );

				$board_to_board = '';
				$board_link = '';
				$message_type_label = '';
				$delete_link = '';

				if ( $wgUser->getName() != $message['user_name_from'] ) {
					$board_to_board = '<a href="' . UserBoard::getUserBoardToBoardURL( $message['user_name'], $message['user_name_from'] ) . '">' .
						wfMessage( 'userboard_board-to-board' )->plain() . '</a>';
					$board_link = '<a href="' . UserBoard::getUserBoardURL( $message['user_name_from'] ) . '">' .
						wfMessage( 'userboard_sendmessage', $message['user_name_from'] )->parse() . '</a>';
				}
				if ( $wgUser->getName() == $message['user_name'] || $wgUser->isAllowed( 'userboard-delete' ) ) {
					$delete_link = "<span class=\"user-board-red\">
							<a href=\"javascript:void(0);\" data-message-id=\"{$message['id']}\">" .
								wfMessage( 'userboard_delete' )->plain() . '</a>
						</span>';
				}
				if ( $message['type'] == 1 ) {
					$message_type_label = '(' . wfMessage( 'userboard_private' )->plain() . ')';
				}

				$message_text = $message['message_text'];
				# $message_text = preg_replace_callback( "/(<a[^>]*>)(.*?)(<\/a>)/i", 'cut_link_text', $message['message_text'] );

				$sender = htmlspecialchars( $user->getFullURL() );
				$output .= "<div class=\"user-board-message\">
					<div class=\"user-board-message-content\">
						<div class=\"user-board-message-image\">
							<a href=\"{$sender}\" title=\"{$message['user_name_from']}\">{$avatar->getAvatarURL()}</a>
						</div>
						<a href=\"{$sender}\" title=\"{$message['user_name_from']}\">{$message['user_name_from']}</a> {$message_type_label}
						<div class=\"user-board-message-time\">" .
                            wfMessage( 'userboard_posted_ago', $this->getTimeAgo( $message['timestamp'] ) )->parse() .
                        "</div>
						<div class=\"cleared\"></div>
					</div>
					<div class=\"user-board-message-body\">
					    {$message_text}
					</div>
					<div class=\"user-board-message-links\">
						{$board_link}
						{$board_to_board}
						{$delete_link}
					</div>
				</div>";
			}
		} elseif ( $wgUser->getName() == $wgTitle->getText() ) {
			$output .= '<div class="no-info-container">' .
				wfMessage( 'userboard_nomessages' )->parse() .
			'</div>';

		}

		return $output;
	}

	/**
	 * Get the escaped full URL to Special:SendBoardBlast.
	 * This is just a silly wrapper function.
	 *
	 * @return String: escaped full URL to Special:SendBoardBlast
	 */
	static function getBoardBlastURL() {
		$title = SpecialPage::getTitleFor( 'SendBoardBlast' );
		return htmlspecialchars( $title->getFullURL() );
	}

	/**
	 * Get the user board URL for $user_name.
	 *
	 * @param $user_name Mixed: name of the user whose user board URL we're
	 *							going to get.
	 * @return String: escaped full URL to the user board page
	 */
	static function getUserBoardURL( $user_name ) {
		$title = SpecialPage::getTitleFor( 'UserBoard' );
		$user_name = str_replace( '&', '%26', $user_name );
		return htmlspecialchars( $title->getFullURL( 'user=' . $user_name ) );
	}

	/**
	 * Get the board-to-board URL for the users $user_name_1 and $user_name_2.
	 *
	 * @param $user_name_1 Mixed: name of the first user
	 * @param $user_name_2 Mixed: name of the second user
	 * @return String: escaped full URL to the board-to-board conversation
	 */
	static function getUserBoardToBoardURL( $user_name_1, $user_name_2 ) {
		$title = SpecialPage::getTitleFor( 'UserBoard' );
		$user_name_1 = str_replace( '&', '%26', $user_name_1 );
		$user_name_2 = str_replace( '&', '%26', $user_name_2 );
		return htmlspecialchars( $title->getFullURL( 'user=' . $user_name_1 . '&conv=' . $user_name_2 ) );
	}

	/**
	 * Gets the difference between two given dates
	 *
	 * @param $dt1 Mixed: current time, as returned by PHP's time() function
	 * @param $dt2 Mixed: date
	 * @return Difference between dates
	 */
	public function dateDiff( $date1, $date2 ) {
		$dtDiff = $date1 - $date2;

		$totalDays = intval( $dtDiff / ( 24 * 60 * 60 ) );
		$totalSecs = $dtDiff - ( $totalDays * 24 * 60 * 60 );
		$dif['w'] = intval( $totalDays / 7 );
		$dif['d'] = $totalDays;
		$dif['h'] = $h = intval( $totalSecs / ( 60 * 60 ) );
		$dif['m'] = $m = intval( ( $totalSecs - ( $h * 60 * 60 ) ) / 60 );
		$dif['s'] = $totalSecs - ( $h * 60 * 60 ) - ( $m * 60 );

		return $dif;
	}

	public function getTimeOffset( $time, $timeabrv, $timename ) {
		return HuijiFunctions::getTimeOffset( $time, $timeabrv, $timename );
	}

	/**
	 * Gets the time how long ago the given board message was posted
	 *
	 * @param $time
	 * @return $timeStr Mixed: time, such as "20 days" or "11 hours"
	 */
	public function getTimeAgo( $time ) {
		return HuijiFunctions::getTimeAgo( $time );
	}

	/**
	* Used to pass Echo your definition for the notification category and the 
	* notification itself (as well as any custom icons).
	* 
    *
	*@see https://www.mediawiki.org/wiki/Echo_%28Notifications%29/Developer_guide
	*/
	public static function onBeforeCreateEchoEvent( &$notifications, &$notificationCategories, &$icons ) {
        $notificationCategories['board-msg'] = array(
            'priority' => 3,
            'tooltip' => 'echo-pref-tooltip-board-msg',
        );
        $notifications['board-msg'] = array(
            'category' => 'board-msg',
            'group' => 'positive',
            'formatter-class' => 'EchoBoardFormatter',
            'title-message' => 'notification-board',
            'title-params' => array( 'agent', 'b2b', 'main-title-text' ),
            'flyout-message' => 'notification-board-flyout',
            'flyout-params' => array( 'agent', 'b2b', 'main-title-text' ),
            'payload' => array( 'summary' ),
            'email-subject-message' => 'notification-board-email-subject',
            'email-subject-params' => array( 'agent', 'b2b', 'main-title-text' ),
            'email-body-message' => 'notification-board-email-body',
            'email-body-params' => array( 'agent', 'b2b', 'main-title-text', 'email-footer' ),
            'email-body-batch-message' => 'notification-board-email-batch-body',
            'email-body-batch-params' => array( 'agent', 'b2b', 'main-title-text' ),
            'icon' => 'chat',
        );
        return true;
    }


	/**
	* Used to define who gets the notifications (for example, the user who performed the edit)
	* 
    *
	*@see https://www.mediawiki.org/wiki/Echo_%28Notifications%29/Developer_guide
	*/
	public static function onEchoGetDefaultNotifiedUsers( $event, &$users ) {
	 	switch ( $event->getType() ) {
	 		case 'board-msg':
	 			$extra = $event->getExtra();
	 			if ( !$extra ){
	 				break;
	 			}
	 			if ( !isset( $extra['board-user-id'] ) ) {
	 				break;
	 			}
	 			if ( isset( $extra['mentioned-users'] ) ){
	 				$users = $extra['mentioned-users'];
	 				break;
	 			}
	 			$recipientId = $extra['board-user-id'];
	 			$recipient = User::newFromId( $recipientId );
	 			$users[$recipientId] = $recipient;
	 			break;
	 	}
	 	return true;
	}

}
class EchoBoardFormatter extends EchoCommentFormatter {

	protected function formatPayload( $payload, $event, $user ) {
		switch ( $payload ) {
		   	case 'summary': 
				$eventData = $event->getExtra();
	        	if ( !isset( $eventData['board-content']) ) {
	                return;
	            }
			    return $eventData['board-content'];
		        break;
		   	default:
		        return parent::formatPayload( $payload, $event, $user );
		        break;
		}
	}
   /**
     * @param $event EchoEvent
     * @param $param
     * @param $message Message
     * @param $user User
     */
    protected function processParam( $event, $param, $message, $user ) {
        if ( $param === 'b2b' ) {
            $eventData = $event->getExtra();
            if ( !isset( $eventData['board-user']) || !isset( $eventData['board-user-conv'] ) ) {
                $message->params( '' );
                return;
            }
            if ( isset( $eventData['mentioned-users'])){
	            $this->setTitleLink(
	                $event,
	                $message,
	                array(
	                    'class' => 'mw-echo-board-msg',
	                    'linkText' => wfMessage( 'notification-board-msg-mention-link' )->text(),
	                    'param' => array(
	                        'user' => $eventData['board-user'],
	                        'conv' => $eventData['board-user-conv'],
	                    )
	                )
	            );             	

            } else {
	            $this->setTitleLink(
	                $event,
	                $message,
	                array(
	                    'class' => 'mw-echo-board-msg',
	                    'linkText' => wfMessage( 'notification-board-msg-link' )->text(),
	                    'param' => array(
	                        'user' => $eventData['board-user'],
	                        'conv' => $eventData['board-user-conv'],
	                    )
	                )
	            );            	
            } 

        } else {
            parent::processParam( $event, $param, $message, $user );
        }
    }
}
