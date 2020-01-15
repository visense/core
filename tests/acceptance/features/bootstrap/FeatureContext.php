<?php
/**
 * ownCloud
 *
 * @author Sergio Bertolin <sbertolin@owncloud.com>
 * @author Phillip Davis <phil@jankaritech.com>
 * @copyright Copyright (c) 2018, ownCloud GmbH
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License,
 * as published by the Free Software Foundation;
 * either version 3 of the License, or any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */

use rdx\behatvars\BehatVariablesContext;
use Zend\Ldap\Ldap;

require_once 'bootstrap.php';

/**
 * Features context.
 */
class FeatureContext extends BehatVariablesContext {
	use BasicStructure;

	/**
	 * @var Ldap
	 */
	private $ldap;
	private $ldapBaseDN;
	private $ldapHost;
	private $ldapPort;
	private $ldapAdminUser;
	private $ldapAdminPassword;
	private $ldapUsersOU;
	private $ldapGroupsOU;
	/**
	 * @var integer
	 */
	private $countLDAPUsersCreated;
	/**
	 * @var array
	 */
	private $toDeleteDNs = [];
	private $ldapCreatedUsers = [];
	private $ldapCreatedGroups = [];
	private $toDeleteLdapConfigs = [];
	private $oldConfig = [];

	/**
	 * @return Ldap
	 */
	public function getLdap() {
		return $this->ldap;
	}

	/**
	 * @return string
	 */
	public function getLdapAdminUser() {
		return $this->ldapAdminUser;
	}

	/**
	 * @return string
	 */
	public function getLdapAdminPassword() {
		return $this->ldapAdminPassword;
	}

	/**
	 * @return string
	 */
	public function getLdapBaseDN() {
		return $this->ldapBaseDN;
	}

	/**
	 * @return string
	 */
	public function getLdapHost() {
		return $this->ldapHost;
	}

	/**
	 * @return string
	 */
	public function getLdapHostWithoutScheme() {
		return $this->removeSchemeFromUrl($this->ldapHost);
	}

	/**
	 * @return string
	 */
	public function getLdapUsersOU() {
		return $this->ldapUsersOU;
	}

	/**
	 * @return string
	 */
	public function getLdapGroupsOU() {
		return $this->ldapGroupsOU;
	}

	/**
	 * @return string
	 */
	public function getLdapPort() {
		return $this->ldapPort;
	}

	/**
	 * @return void
	 */
	protected function resetAppConfigs() {
		// Remember the current capabilities
		$this->theAdministratorGetsCapabilitiesCheckResponse();
		$this->savedCapabilitiesXml[$this->getBaseUrl()] = $this->getCapabilitiesXml();
		// Set the required starting values for testing
		$this->setCapabilities($this->getCommonSharingConfigs());
	}
}
