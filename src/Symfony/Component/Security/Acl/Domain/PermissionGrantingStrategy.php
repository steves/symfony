<?php

namespace Symfony\Component\Security\Acl\Domain;

use Symfony\Component\Security\Acl\Exception\NoAceFoundException;
use Symfony\Component\Security\Acl\Exception\SidNotLoadedException;
use Symfony\Component\Security\Acl\Model\AclInterface;
use Symfony\Component\Security\Acl\Model\AuditLoggerInterface;
use Symfony\Component\Security\Acl\Model\EntryInterface;
use Symfony\Component\Security\Acl\Model\PermissionGrantingStrategyInterface;
use Symfony\Component\Security\Acl\Model\PermissionInterface;
use Symfony\Component\Security\Acl\Model\SecurityIdentityInterface;

/*
 * This file is part of the Symfony framework.
 *
 * (c) Fabien Potencier <fabien.potencier@symfony-project.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * The permission granting strategy to apply to the access control list.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class PermissionGrantingStrategy implements PermissionGrantingStrategyInterface
{
    const EQUAL = 'equal';
    const ALL   = 'all';
    const ANY   = 'any';

    protected $auditLogger;

    /**
     * Sets the audit logger
     *
     * @param AuditLoggerInterface $auditLogger
     * @return void
     */
    public function setAuditLogger(AuditLoggerInterface $auditLogger)
    {
        $this->auditLogger = $auditLogger;
    }

    /**
     * Returns the audit logger
     *
     * @return AuditLoggerInterface
     */
    public function getAuditLogger()
    {
        return $this->auditLogger;
    }

    /**
     * {@inheritDoc}
     */
    public function isGranted(AclInterface $acl, array $masks, array $sids, $administrativeMode = false)
    {
        try {
            try {
                $aces = $acl->getObjectAces();

                if (0 === count($aces)) {
                    throw new NoAceFoundException('No applicable ACE was found.');
                }

                return $this->hasSufficientPermissions($acl, $aces, $masks, $sids, $administrativeMode);
            } catch (NoAceFoundException $noObjectAce) {
                $aces = $acl->getClassAces();

                if (0 === count($aces)) {
                    throw new NoAceFoundException('No applicable ACE was found.');
                }

                return $this->hasSufficientPermissions($acl, $aces, $masks, $sids, $administrativeMode);
            }
        } catch (NoAceFoundException $noClassAce) {
            if ($acl->isEntriesInheriting() && null !== $parentAcl = $acl->getParentAcl()) {
                return $parentAcl->isGranted($masks, $sids, $administrativeMode);
            }

            throw new NoAceFoundException('No applicable ACE was found.');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function isFieldGranted(AclInterface $acl, $field, array $masks, array $sids, $administrativeMode = false)
    {
        try {
            try {
                $aces = $acl->getObjectFieldAces($field);
                if (0 === count($aces)) {
                    throw new NoAceFoundException('No applicable ACE was found.');
                }

                return $this->hasSufficientPermissions($acl, $aces, $masks, $sids, $administrativeMode);
            } catch (NoAceFoundException $noObjectAces) {
                $aces = $acl->getClassFieldAces($field);
                if (0 === count($aces)) {
                    throw new NoAceFoundException('No applicable ACE was found.');
                }

                return $this->hasSufficientPermissions($acl, $aces, $masks, $sids, $administrativeMode);
            }
        } catch (NoAceFoundException $noClassAces) {
            if ($acl->isEntriesInheriting() && null !== $parentAcl = $acl->getParentAcl()) {
                return $parentAcl->isFieldGranted($field, $masks, $sids, $administrativeMode);
            }

            throw new NoAceFoundException('No applicable ACE was found.');
        }
    }

    /**
     * Makes an authorization decision.
     *
     * The order of ACEs, and SIDs is significant; the order of permission masks
     * not so much. It is important to note that the more specific security
     * identities should be at the beginning of the SIDs array in order for this
     * strategy to produce intuitive authorization decisions.
     *
     * First, we will iterate over permissions, then over security identities.
     * For each combination of permission, and identity we will test the
     * available ACEs until we find one which is applicable.
     *
     * The first applicable ACE will make the ultimate decision for the
     * permission/identity combination. If it is granting, this method will return
     * true, if it is denying, the method will continue to check the next
     * permission/identity combination.
     *
     * This process is repeated until either a granting ACE is found, or no
     * permission/identity combinations are left. In the latter case, we will
     * call this method on the parent ACL if it exists, and isEntriesInheriting
     * is true. Otherwise, we will either throw an NoAceFoundException, or deny
     * access finally.
     *
     * @param AclInterface $acl
     * @param array $aces an array of ACE to check against
     * @param array $masks an array of permission masks
     * @param array $sids an array of SecurityIdentityInterface implementations
     * @param Boolean $administrativeMode true turns off audit logging
     * @return Boolean true, or false; either granting, or denying access respectively.
     */
    protected function hasSufficientPermissions(AclInterface $acl, array $aces, array $masks, array $sids, $administrativeMode)
    {
        $firstRejectedAce  = null;

        foreach ($masks as $requiredMask) {
            foreach ($sids as $sid) {
                if (!$acl->isSidLoaded($sid)) {
                    throw new SidNotLoadedException(sprintf('The SID "%s" has not been loaded.', $sid));
                }

                foreach ($aces as $ace) {
                    if ($this->isAceApplicable($requiredMask, $sid, $ace)) {
                        if ($ace->isGranting()) {
                            if (!$administrativeMode && null !== $this->auditLogger) {
                                $this->auditLogger->logIfNeeded(true, $ace);
                            }

                            return true;
                        }

                        if (null === $firstRejectedAce) {
                            $firstRejectedAce = $ace;
                        }

                        break 2;
                    }
                }
            }
        }

        if (null !== $firstRejectedAce) {
            if (!$administrativeMode && null !== $this->auditLogger) {
                $this->auditLogger->logIfNeeded(false, $firstRejectedAce);
            }

            return false;
        }

        throw new NoAceFoundException('No applicable ACE was found.');
    }

    /**
     * Determines whether the ACE is applicable to the given permission/security
     * identity combination.
     *
     * Per default, we support three different comparison strategies.
     *
     * Strategy ALL:
     * The ACE will be considered applicable when all the turned-on bits in the
     * required mask are also turned-on in the ACE mask.
     *
     * Strategy ANY:
     * The ACE will be considered applicable when any of the turned-on bits in
     * the required mask is also turned-on the in the ACE mask.
     *
     * Strategy EQUAL:
     * The ACE will be considered applicable when the bitmasks are equal.
     *
     * @param SecurityIdentityInterface $sid
     * @param EntryInterface $ace
     * @param int $requiredMask
     * @return Boolean
     */
    protected function isAceApplicable($requiredMask, SecurityIdentityInterface $sid, EntryInterface $ace)
    {
        if (false === $ace->getSecurityIdentity()->equals($sid)) {
            return false;
        }

        $strategy = $ace->getStrategy();
        if (self::ALL === $strategy) {
            return $requiredMask === ($ace->getMask() & $requiredMask);
        } else if (self::ANY === $strategy) {
            return 0 !== ($ace->getMask() & $requiredMask);
        } else if (self::EQUAL === $strategy) {
            return $requiredMask === $ace->getMask();
        } else {
            throw new \RuntimeException(sprintf('The strategy "%s" is not supported.', $strategy));
        }
    }
}