<?php
namespace CreditManager\Service;

use Concrete\Core\Support\Facade\Config;
use Concrete\Core\User\Group\Group;

/**
 * The event ("LAN") the POS and order pages currently work for.
 *
 * Production supplies `Application\Turicane\CurrentLan`; where that class is missing the values come from the
 * config keys `credit_manager.event.page_id`, `credit_manager.event.title` and
 * `credit_manager.event.participant_group_id`, so the package works (and can be tested) without it.
 */
class EventContext
{
    const CURRENT_LAN_CLASS = 'Application\\Turicane\\CurrentLan';

    /**
     * @return int|null page id of the current event
     */
    public static function getEventPageId()
    {
        if (self::hasCurrentLan()) {
            $class = self::CURRENT_LAN_CLASS;
            $id = $class::$lanPageId;
            return $id ? (int) $id : null;
        }
        $id = (int) Config::get('credit_manager.event.page_id');
        return $id > 0 ? $id : null;
    }

    /**
     * @return string title of the current event, empty when there is none
     */
    public static function getEventTitle()
    {
        if (self::hasCurrentLan()) {
            $class = self::CURRENT_LAN_CLASS;
            return (string) $class::getLANTitle();
        }
        return (string) Config::get('credit_manager.event.title');
    }

    /**
     * @return Group|null the group of the current event's participants
     */
    public static function getParticipantGroup()
    {
        if (self::hasCurrentLan()) {
            $class = self::CURRENT_LAN_CLASS;
            $group = $class::getParticipantGroup();
            return $group instanceof Group ? $group : null;
        }
        $gID = (int) Config::get('credit_manager.event.participant_group_id');
        if ($gID <= 0) {
            return null;
        }
        $group = Group::getByID($gID);
        return $group instanceof Group ? $group : null;
    }

    public static function hasCurrentLan()
    {
        return class_exists(self::CURRENT_LAN_CLASS);
    }
}
