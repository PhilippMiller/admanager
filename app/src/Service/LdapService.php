<?php

namespace App\Service;

use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Ldap;
use Symfony\Component\Ldap\Adapter\ExtLdap\Connection as ExtLdapConnection;

class LdapService
{
    private Ldap $ldap;
    private ExtLdapConnection $extConnection;
    private string $baseDn;

    public function __construct(
        string $host,
        int $port,
        string $baseDn,
        string $userDn,
        string $password
    ) {
        $this->baseDn = $baseDn;

        $encryption = $_ENV['LDAP_ENCRYPTION'] ?? (str_starts_with($host, 'ldaps://') ? 'ssl' : 'none');
        $host = preg_replace('#^ldaps?://#', '', $host);

        if ($_ENV['LDAP_IGNORE_CERT'] == 1) {
            putenv('LDAPTLS_REQCERT=never');
        }

        // Symfony-konforme Verbindung
        $this->ldap = Ldap::create('ext_ldap', [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
        ]);

        $this->ldap->bind($userDn, $password);

        // Zugriff auf den nativen LDAP-Roh-Handle
        $this->extConnection = $this->getExtConnectionFromLdap($this->ldap);
    }

    private function getExtConnectionFromLdap(Ldap $ldap): ExtLdapConnection
    {
        $refLdap = new \ReflectionObject($ldap);
        $adapterProp = $refLdap->getProperty('adapter');
        $adapterProp->setAccessible(true);
        $adapter = $adapterProp->getValue($ldap);

        $refAdapter = new \ReflectionObject($adapter);
        $connProp = $refAdapter->getProperty('connection');
        $connProp->setAccessible(true);

        return $connProp->getValue($adapter);
    }

    public function findUser(?string $samAccountName, ?string $email): ?array
    {
        if (!$samAccountName && !$email) {
            return null;
        }

        $filter = $samAccountName
            ? "(sAMAccountName=$samAccountName)"
            : "(mail=$email)";

        $query = $this->ldap->query($this->baseDn, $filter);
        $results = $query->execute();

        if (count($results) === 0) {
            return null;
        }

        $entry = $results[0];
        $lockoutTime = $entry->getAttribute('lockoutTime')[0] ?? null;
        $uac = $entry->getAttribute('userAccountControl')[0] ?? null;
        $lastLogonRaw = $entry->getAttribute('lastLogonTimestamp')[0] ?? null;

        // isLocked info
        $isLocked = isset($lockoutTime) && $lockoutTime !== '0';

        // isDisabled info
        $isDisabled = false;
        if ($uac !== null) {
            $isDisabled = ((int)$uac & 0x2) === 0x2;
        }

        // lastLogonTimestamp info
        $lastLogon = null;
        if ($lastLogonRaw && is_numeric($lastLogonRaw)) {
            $windowsTimestamp = (int)$lastLogonRaw;
            // AD-Zeit beginnt am 1.1.1601, Unix am 1.1.1970 → 11644473600 Sekunden Unterschied
            $lastLogonUnix = (int)($windowsTimestamp / 10000000 - 11644473600);
            $lastLogon = (new \DateTime())->setTimestamp($lastLogonUnix)->format('Y-m-d H:i:s');
        }


        return [
            'dn' => $entry->getDn(),
            'cn' => $entry->getAttribute('cn')[0] ?? null,
            'mail' => $entry->getAttribute('mail')[0] ?? null,
            'sAMAccountName' => $entry->getAttribute('sAMAccountName')[0] ?? null,
            'memberOf' => array_map(function ($dn) {
                // Extrahiere nur den CN-Teil
                if (preg_match('/CN=([^,]+)/', $dn, $matches)) {
                    return $matches[1];
                }
                return $dn; // fallback
            }, $entry->getAttribute('memberOf') ?? []),
            'isLocked' => $isLocked,
            'isDisabled' => $isDisabled,
            'lastLogon' => $lastLogon,
            'position' => $entry->getAttribute('title')[0] ?? null,
            'department' => $entry->getAttribute('department')[0] ?? null,
            'description' => $entry->getAttribute('description')[0] ?? null
        ];
    }

    public function getDnBySamAccountName(string $samAccountName): ?string
    {
        $filter = "(sAMAccountName=$samAccountName)";
        $query = $this->ldap->query($this->baseDn, $filter);
        $results = $query->execute();

        return count($results) ? $results[0]->getDn() : null;
    }

    public function unlockUserByDn(string $dn): void
    {
        $ldapResource = $this->extConnection->getResource();

        $modifications = [
            [
                'attrib' => 'lockoutTime',
                'modtype' => LDAP_MODIFY_BATCH_REPLACE,
                'values' => ['0'],
            ],
        ];

        if (!@ldap_modify_batch($ldapResource, $dn, $modifications)) {
            $error = ldap_error($ldapResource);
            throw new \RuntimeException("Unlock fehlgeschlagen: $error");
        }
    }

    public function disableUserByDn(string $dn): void
    {
        $query = $this->ldap->query($dn, '(objectClass=*)');
        $results = $query->execute();

        if (count($results) === 0) {
            throw new \RuntimeException("Benutzer nicht gefunden: $dn");
        }

        /** @var Entry $entry */
        $entry = $results[0];

        $currentValue = $entry->getAttribute('userAccountControl')[0] ?? null;

        if ($currentValue === null) {
            throw new \RuntimeException("userAccountControl nicht vorhanden.");
        }

        $disabledFlag = 0x2;
        $newValue = (int)$currentValue | $disabledFlag;

        $modifications = [
            [
                'attrib' => 'userAccountControl',
                'modtype' => LDAP_MODIFY_BATCH_REPLACE,
                'values' => [$newValue],
            ],
        ];

        $ldapResource = $this->extConnection->getResource();

        if (!@ldap_modify_batch($ldapResource, $dn, $modifications)) {
            $error = ldap_error($ldapResource);
            throw new \RuntimeException("Deaktivierung fehlgeschlagen: $error");
        }
    }

    public function enableUserByDn(string $dn): void
    {
        $query = $this->ldap->query($dn, '(objectClass=*)');
        $results = $query->execute();

        if (count($results) === 0) {
            throw new \RuntimeException("Benutzer nicht gefunden: $dn");
        }

        /** @var Entry $entry */
        $entry = $results[0];

        $currentValue = $entry->getAttribute('userAccountControl')[0] ?? null;

        if ($currentValue === null) {
            throw new \RuntimeException("userAccountControl nicht vorhanden.");
        }

        $disabledFlag = 0x2;
        $newValue = (int)$currentValue & ~$disabledFlag;

        $modifications = [
            [
                'attrib' => 'userAccountControl',
                'modtype' => LDAP_MODIFY_BATCH_REPLACE,
                'values' => [$newValue],
            ],
        ];

        $ldapResource = $this->extConnection->getResource();

        if (!@ldap_modify_batch($ldapResource, $dn, $modifications)) {
            $error = ldap_error($ldapResource);
            throw new \RuntimeException("Aktivierung fehlgeschlagen: $error");
        }
    }

    public function resetPasswordByDn(string $dn, string $newPassword): void
    {
        $ldapResource = $this->extConnection->getResource();

        // Passwort im AD-Format codieren
        $encoded = mb_convert_encoding('"' . $newPassword . '"', 'UTF-16LE');

        $modifications = [
            [
                'attrib' => 'unicodePwd',
                'modtype' => LDAP_MODIFY_BATCH_REPLACE,
                'values' => [$encoded],
            ],
        ];

        if (!@ldap_modify_batch($ldapResource, $dn, $modifications)) {
            $error = ldap_error($ldapResource);
            throw new \RuntimeException("Passwortänderung fehlgeschlagen: $error");
        }
    }

    public function getAllGroups(): array
    {
        $query = $this->ldap->query($this->baseDn, '(&(objectCategory=group))', [
            'scope' => 'sub' // Sehr wichtig für verschachtelte OUs
        ]);

        $results = $query->execute();

        return array_map(fn($entry) => [
            'cn' => $entry->getAttribute('cn')[0] ?? null,
            'dn' => $entry->getDn(),
        ], iterator_to_array($results));
    }

    public function getGroupMembersByCn(string $cn): array
    {
        $groupDn = $this->resolveGroupDnByCn($cn);
        if (!$groupDn) {
            throw new \RuntimeException("Gruppe nicht gefunden");
        }

        $query = $this->ldap->query($groupDn, '(objectClass=*)');
        $results = $query->execute();

        if (count($results) === 0) {
            throw new \RuntimeException("Gruppe nicht gefunden");
        }

        $entry = $results[0];
        $members = $entry->getAttribute('member') ?? [];

        // Jetzt: Hole zu jedem member-DN die cn, mail etc.
        $userInfos = [];
        foreach ($members as $memberDn) {
            try {
                $userQuery = $this->ldap->query($memberDn, '(objectClass=*)');
                $userResults = $userQuery->execute();
                if (count($userResults) > 0) {
                    $userEntry = $userResults[0];
                    $userInfos[] = [
                        'dn' => $memberDn,
                        'cn' => $userEntry->getAttribute('cn')[0] ?? null,
                        'mail' => $userEntry->getAttribute('mail')[0] ?? null,
                        'sAMAccountName' => $userEntry->getAttribute('sAMAccountName')[0] ?? null,
                    ];
                }
            } catch (\Throwable $t) {
                // ignore broken entries
            }
        }

        return $userInfos;
    }

    public function addUserToGroup(string $samAccountName, string $groupDn): void
    {
        $userDn = $this->getDnBySamAccountName($samAccountName);
        if (!$userDn) {
            throw new \RuntimeException("Benutzer nicht gefunden");
        }

        $mod = [[
            'attrib' => 'member',
            'modtype' => LDAP_MODIFY_BATCH_ADD,
            'values' => [$userDn],
        ]];

        if (!@ldap_modify_batch($this->extConnection->getResource(), $groupDn, $mod)) {
            $error = ldap_error($this->extConnection->getResource());
            throw new \RuntimeException("Fehler beim Hinzufügen: $error");
        }
    }

    public function removeUserFromGroup(string $samAccountName, string $groupDn): void
    {
        $userDn = $this->getDnBySamAccountName($samAccountName);
        if (!$userDn) {
            throw new \RuntimeException("Benutzer nicht gefunden");
        }

        $mod = [[
            'attrib' => 'member',
            'modtype' => LDAP_MODIFY_BATCH_REMOVE,
            'values' => [$userDn],
        ]];

        if (!@ldap_modify_batch($this->extConnection->getResource(), $groupDn, $mod)) {
            $error = ldap_error($this->extConnection->getResource());
            throw new \RuntimeException("Fehler beim Entfernen: $error");
        }
    }

    public function resolveGroupDnByCn(string $cn): ?string
    {
        $query = $this->ldap->query($this->baseDn, "(cn=$cn)", ['scope' => 'sub']);
        $results = $query->execute();

        return count($results) ? $results[0]->getDn() : null;
    }

}
