<?php
/**
 * ownCloud
 *
 * @author Joas Schilling <coding@schilljs.com>
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

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use PHPUnit\Framework\Assert;
use TestHelpers\AppConfigHelper;
use TestHelpers\HttpRequestHelper;
use TestHelpers\OcsApiHelper;
use TestHelpers\SetupHelper;
use Behat\Gherkin\Node\TableNode;
use Behat\Behat\Context\Context;

/**
 * AppConfiguration trait
 */
class AppConfigurationContext implements Context {
	/**
	 * @var WebUIGeneralContext
	 */
	private $webUIGeneralContext;

	/**
	 * @var array with keys for each baseURL (e.g. of local and remote server)
	 *            that contain the original capabilities in XML format
	 */
	private $savedCapabilitiesXml;

	/**
	 * @var array the changes made to capabilities for the test scenario
	 */
	private $savedCapabilitiesChanges = [];

	/**
	 * @var array saved configuration of the system before test runs as reported
	 *            by occ config:list
	 */
	private $savedConfigList = [];

	/**
	 * @var array
	 */
	private $initialTrustedServer;

	/**
	 * @var FeatureContext
	 */
	private $featureContext;

	/**
	 * @When /^the administrator sets parameter "([^"]*)" of app "([^"]*)" to "([^"]*)"$/
	 *
	 * @param string $parameter
	 * @param string $app
	 * @param string $value
	 *
	 * @return void
	 */
	public function adminSetsServerParameterToUsingAPI(
		$parameter, $app, $value
	) {
		$user = $this->featureContext->getCurrentUser();
		$this->featureContext->setCurrentUser($this->featureContext->getAdminUsername());

		$this->modifyAppConfig($app, $parameter, $value);

		$this->featureContext->setCurrentUser($user);
	}

	/**
	 * @Given /^parameter "([^"]*)" of app "([^"]*)" has been set to ((?:'[^']*')|(?:"[^"]*"))$/
	 *
	 * @param string $parameter
	 * @param string $app
	 * @param string $value
	 *
	 * @return void
	 */
	public function serverParameterHasBeenSetTo($parameter, $app, $value) {
		// The capturing group of the regex always includes the quotes at each
		// end of the captured string, so trim them.
		$value = \trim($value, $value[0]);
		$this->adminSetsServerParameterToUsingAPI($parameter, $app, $value);
	}

	/**
	 * @Then the capabilities setting of :capabilitiesApp path :capabilitiesPath should be :expectedValue
	 * @Given the capabilities setting of :capabilitiesApp path :capabilitiesPath has been confirmed to be :expectedValue
	 *
	 * @param string $capabilitiesApp the "app" name in the capabilities response
	 * @param string $capabilitiesPath the path to the element
	 * @param string $expectedValue
	 *
	 * @return void
	 */
	public function theCapabilitiesSettingOfAppParameterShouldBe(
		$capabilitiesApp, $capabilitiesPath, $expectedValue
	) {
		$this->theAdministratorGetsCapabilitiesCheckResponse();

		Assert::assertEquals(
			$expectedValue,
			$this->getAppParameter($capabilitiesApp, $capabilitiesPath)
		);
	}

	/**
	 * @param string $capabilitiesApp the "app" name in the capabilities response
	 * @param string $capabilitiesPath the path to the element
	 *
	 * @return string
	 */
	public function getAppParameter($capabilitiesApp, $capabilitiesPath) {
		$answeredValue = $this->getParameterValueFromXml(
			$this->getCapabilitiesXml(),
			$capabilitiesApp,
			$capabilitiesPath
		);

		return (string) $answeredValue;
	}

	/**
	 * @When user :username retrieves the capabilities using the capabilities API
	 *
	 * @param string $username
	 *
	 * @return void
	 */
	public function userGetsCapabilities($username) {
		$user = $this->featureContext->getActualUsername($username);
		$password = $this->featureContext->getPasswordForUser($user);
		$this->featureContext->setResponse(
			OcsApiHelper::sendRequest(
				$this->featureContext->getBaseUrl(), $user, $password, 'GET', '/cloud/capabilities',
				[], $this->featureContext->getOcsApiVersion()
			)
		);
	}

	/**
	 * @Given user :username has retrieved the capabilities
	 *
	 * @param string $username
	 *
	 * @return void
	 */
	public function userGetsCapabilitiesCheckResponse($username) {
		$this->userGetsCapabilities($username);
		Assert::assertEquals(
			200, $this->featureContext->getResponse()->getStatusCode()
		);
	}

	/**
	 * @When the user retrieves the capabilities using the capabilities API
	 *
	 * @return void
	 */
	public function theUserGetsCapabilities() {
		$this->userGetsCapabilities($this->featureContext->getCurrentUser());
	}

	/**
	 * @Given the user has retrieved the capabilities
	 *
	 * @return void
	 */
	public function theUserGetsCapabilitiesCheckResponse() {
		$this->userGetsCapabilitiesCheckResponse($this->featureContext->getCurrentUser());
	}

	/**
	 * @When the administrator retrieves the capabilities using the capabilities API
	 *
	 * @return void
	 */
	public function theAdministratorGetsCapabilities() {
		$this->userGetsCapabilities($this->featureContext->getAdminUsername());
	}

	/**
	 * @Given the administrator has retrieved the capabilities
	 *
	 * @return void
	 */
	public function theAdministratorGetsCapabilitiesCheckResponse() {
		$this->userGetsCapabilitiesCheckResponse($this->featureContext->getAdminUsername());
	}

	/**
	 * @return string latest retrieved capabilities in XML format
	 */
	public function getCapabilitiesXml() {
		return $this->featureContext->getResponseXml()->data->capabilities;
	}

	/**
	 * @param string $xml of the capabilities
	 * @param string $capabilitiesApp the "app" name in the capabilities response
	 * @param string $capabilitiesPath the path to the element
	 *
	 * @return string
	 */
	public function getParameterValueFromXml(
		$xml, $capabilitiesApp, $capabilitiesPath
	) {
		$path_to_element = \explode('@@@', $capabilitiesPath);
		$answeredValue = $xml->{$capabilitiesApp};
		foreach ($path_to_element as $element) {
			$nameIndexParts = \explode('[', $element);
			if (isset($nameIndexParts[1])) {
				// This part of the path should be something like "some_element[1]"
				// Separately extract the name and the index
				$name = $nameIndexParts[0];
				$index = (int) \explode(']', $nameIndexParts[1])[0];
				// and use those to construct the reference into the next XML level
				$answeredValue = $answeredValue->{$name}[$index];
			} else {
				if ($element !== "") {
					$answeredValue = $answeredValue->{$element};
				}
			}
		}

		return (string) $answeredValue;
	}

	/**
	 * @param string $xml of the capabilities
	 * @param string $capabilitiesApp the "app" name in the capabilities response
	 * @param string $capabilitiesPath the path to the element
	 *
	 * @return boolean
	 */
	public function parameterValueExistsInXml(
		$xml, $capabilitiesApp, $capabilitiesPath
	) {
		$path_to_element = \explode('@@@', $capabilitiesPath);
		$answeredValue = $xml->{$capabilitiesApp};

		foreach ($path_to_element as $element) {
			$nameIndexParts = \explode('[', $element);
			if (isset($nameIndexParts[1])) {
				// This part of the path should be something like "some_element[1]"
				// Separately extract the name and the index
				$name = $nameIndexParts[0];
				$index = (int) \explode(']', $nameIndexParts[1])[0];
				// and use those to construct the reference into the next XML level
				if (isset($answeredValue->{$name}[$index])) {
					$answeredValue = $answeredValue->{$name}[$index];
				} else {
					// The path ends at this level
					return false;
				}
			} else {
				if (isset($answeredValue->{$element})) {
					$answeredValue = $answeredValue->{$element};
				} else {
					// The path ends at this level
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * @param string $capabilitiesApp the "app" name in the capabilities response
	 * @param string $capabilitiesParameter the parameter name in the
	 *                                      capabilities response
	 *
	 * @return boolean
	 */
	public function wasCapabilitySet($capabilitiesApp, $capabilitiesParameter) {
		return (bool) $this->getParameterValueFromXml(
			$this->savedCapabilitiesXml[$this->featureContext->getBaseUrl()],
			$capabilitiesApp,
			$capabilitiesParameter
		);
	}

	/**
	 * @param array $capabilitiesArray with each array entry containing keys for:
	 *                                 ['capabilitiesApp'] the "app" name in the capabilities response
	 *                                 ['capabilitiesParameter'] the parameter name in the capabilities response
	 *                                 ['testingApp'] the "app" name as understood by "testing"
	 *                                 ['testingParameter'] the parameter name as understood by "testing"
	 *                                 ['testingState'] boolean state the parameter must be set to for the test
	 *
	 * @return void
	 */
	public function setCapabilities($capabilitiesArray) {
		$savedCapabilitiesChanges = AppConfigHelper::setCapabilities(
			$this->featureContext->getBaseUrl(),
			$this->featureContext->getAdminUsername(),
			$this->featureContext->getAdminPassword(),
			$capabilitiesArray,
			$this->savedCapabilitiesXml[$this->featureContext->getBaseUrl()]
		);

		if (!isset($this->savedCapabilitiesChanges[$this->featureContext->getBaseUrl()])) {
			$this->savedCapabilitiesChanges[$this->featureContext->getBaseUrl()] = [];
		}
		$this->savedCapabilitiesChanges[$this->featureContext->getBaseUrl()] = \array_merge(
			$this->savedCapabilitiesChanges[$this->featureContext->getBaseUrl()],
			$savedCapabilitiesChanges
		);
	}

	/**
	 * @param string $app
	 * @param string $parameter
	 * @param string $value
	 *
	 * @return void
	 */
	protected function modifyAppConfig($app, $parameter, $value) {
		AppConfigHelper::modifyAppConfig(
			$this->featureContext->getBaseUrl(),
			$this->featureContext->getAdminUsername(),
			$this->featureContext->getAdminPassword(),
			$app,
			$parameter,
			$value,
			$this->featureContext->getOcsApiVersion()
		);
	}

	/**
	 * @param array $appParameterValues
	 *
	 * @return void
	 */
	protected function modifyAppConfigs($appParameterValues) {
		AppConfigHelper::modifyAppConfigs(
			$this->featureContext->getBaseUrl(),
			$this->featureContext->getAdminUsername(),
			$this->featureContext->getAdminPassword(),
			$appParameterValues,
			$this->featureContext->getOcsApiVersion()
		);
	}

	/**
	 * @param boolean $enabled if true, then enable the testing app
	 *                         otherwise disable the testing app
	 *
	 * @return void
	 */
	protected function setStatusTestingApp($enabled) {
		$this->featureContext->ocsContext->theUserSendsToOcsApiEndpoint(
			($enabled ? 'post' : 'delete'), '/cloud/apps/testing'
		);
		$this->featureContext->theHTTPStatusCodeShouldBe('200');
		if ($this->featureContext->getOcsApiVersion() == 1) {
			$this->featureContext->ocsContext->theOCSStatusCodeShouldBe('100');
		}

		$this->featureContext->ocsContext->theUserSendsToOcsApiEndpoint('get', '/cloud/apps?filter=enabled');
		$this->featureContext->theHTTPStatusCodeShouldBe('200');
		if ($enabled) {
			Assert::assertContains(
				'testing',
				$this->featureContext->getResponse()->getBody()->getContents()
			);
		} else {
			Assert::assertNotContains(
				'testing',
				$this->featureContext->getResponse()->getBody()->getContents()
			);
		}
	}

	/**
	 * @When the administrator adds url :url as trusted server using the testing API
	 *
	 * @param string $url
	 *
	 * @return void
	 */
	public function theAdministratorAddsUrlAsTrustedServerUsingTheTestingApi($url) {
		$adminUser = $this->featureContext->getAdminUsername();
		$response = OcsApiHelper::sendRequest(
			$this->featureContext->getBaseUrl(),
			$adminUser,
			$this->featureContext->getAdminPassword(),
			'POST',
			"/apps/testing/api/v1/trustedservers",
			['url' => $this->featureContext->substituteInLineCodes($url)]
		);
		$this->featureContext->setResponse($response);
	}

	/**
	 * Return text that contains the details of the URL, including any differences due to inline codes
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	private function getUrlStringForMessage($url) {
		$text = $url;
		$expectedUrl = $this->featureContext->substituteInLineCodes($url);
		if ($expectedUrl !== $url) {
			$text .= " ($expectedUrl)";
		}
		return $text;
	}

	/**
	 * @param string $url
	 *
	 * @return string
	 */
	private function getNotTrustedServerMessage($url) {
		return
			"URL "
			. $this->getUrlStringForMessage($url)
			. " is not a trusted server but should be";
	}

	/**
	 * @Then url :url should be a trusted server
	 *
	 * @param string $url
	 *
	 * @return  void
	 */
	public function urlShouldBeATrustedServer($url) {
		$trustedServers = $this->getTrustedServers();
		foreach ($trustedServers as $server => $id) {
			if ($server === $this->featureContext->substituteInLineCodes($url)) {
				return;
			}
		}
		Assert::fail($this->getNotTrustedServerMessage($url));
	}

	/**
	 * @Then the trusted server list should include these urls:
	 *
	 * @param TableNode $table
	 *
	 * @return void
	 */
	public function theTrustedServerListShouldIncludeTheseUrls(TableNode $table) {
		$trustedServers = $this->getTrustedServers();
		$expected = $table->getColumnsHash();

		foreach ($expected as $server) {
			$found = false;
			foreach ($trustedServers as $url => $id) {
				if ($url === $this->featureContext->substituteInLineCodes($server['url'])) {
					$found = true;
					break;
				}
			}
			if (!$found) {
				Assert::fail($this->getNotTrustedServerMessage($server['url']));
			}
		}
	}

	/**
	 * @Given the administrator has added url :url as trusted server
	 *
	 * @param string $url
	 *
	 * @return void
	 */
	public function theAdministratorHasAddedUrlAsTrustedServer($url) {
		$this->theAdministratorAddsUrlAsTrustedServerUsingTheTestingApi($url);
		$status = $this->featureContext->featureContext->getResponse()->getStatusCode();
		if ($status !== 201) {
			throw new \Exception(
				__METHOD__ .
				"Could not add trusted server " . $this->getUrlStringForMessage($url)
				. ". The request failed with status $status"
			);
		}
	}

	/**
	 * @When the administrator deletes url :url from trusted servers using the testing API
	 *
	 * @param string $url
	 *
	 * @return void
	 */
	public function theAdministratorDeletesUrlFromTrustedServersUsingTheTestingApi($url) {
		$adminUser = $this->featureContext->getAdminUsername();
		$response = OcsApiHelper::sendRequest(
			$this->featureContext->getBaseUrl(),
			$adminUser,
			$this->featureContext->getAdminPassword(),
			'DELETE',
			"/apps/testing/api/v1/trustedservers",
			['url' => $this->featureContext->substituteInLineCodes($url)]
		);
		$this->featureContext->setResponse($response);
	}

	/**
	 * @Then url :url should not be a trusted server
	 *
	 * @param string $url
	 *
	 * @return void
	 */
	public function urlShouldNotBeATrustedServer($url) {
		$trustedServers = $this->getTrustedServers();
		foreach ($trustedServers as $server => $id) {
			if ($server === $this->featureContext->featureContext->substituteInLineCodes($url)) {
				Assert::fail(
					"URL " . $this->getUrlStringForMessage($url)
					. " is a trusted server but is not expected to be"
				);
			}
		}
	}

	/**
	 * @When the administrator deletes all trusted servers using the testing API
	 *
	 * @return void
	 */
	public function theAdministratorDeletesAllTrustedServersUsingTheTestingApi() {
		$adminUser = $this->featureContext->getAdminUsername();
		$response = OcsApiHelper::sendRequest(
			$this->featureContext->getBaseUrl(),
			$adminUser,
			$this->featureContext->getAdminPassword(),
			'DELETE',
			"/apps/testing/api/v1->featureContext/trustedservers/all"
		);
		$this->featureContext->setResponse($response);
	}

	/**
	 * @Given the trusted server list is cleared
	 *
	 * @return void
	 */
	public function theTrustedServerListIsCleared() {
		$this->theAdministratorDeletesAllTrustedServersUsingTheTestingApi();
		Assert::assertEquals(
			204,
			$this->featureContext->getResponse()->getStatusCode(),
			__METHOD__
			. "Failed to clear all trusted servers"
			. $this->featureContext->getResponse()->getBody()->getContents()
		);
	}

	/**
	 * @Then the trusted server list should be empty
	 *
	 * @return void
	 */
	public function theTrustedServerListShouldBeEmpty() {
		$trustedServers = $this->getTrustedServers();
		Assert::assertEmpty($trustedServers, "Trusted server list is not empty");
	}

	/**
	 * Get the array of trusted servers in format ["url" => "id"]
	 *
	 * @param string $server 'LOCAL'/'REMOTE'
	 *
	 * @return array
	 * @throws Exception
	 */
	public function getTrustedServers($server = 'LOCAL') {
		if ($server === 'LOCAL') {
			$url = $this->featureContext->getLocalBaseUrl();
		} elseif ($server === 'REMOTE') {
			$url = $this->featureContext->getRemoteBaseUrl();
		} else {
			throw new \Exception(__METHOD__ . "Invalid value for server : $server");
		}
		$adminUser = $this->featureContext->getAdminUsername();
		$response = OcsApiHelper::sendRequest(
			$url,
			$adminUser,
			$this->featureContext->getAdminPassword(),
			'GET',
			"/apps/testing/api/v1/trustedservers"
		);
		if ($response->getStatusCode() !== 200) {
			throw new Exception("Could not get the list of trusted servers" . $response->getBody()->getContents());
		}
		$responseXml = HttpRequestHelper::getResponseXml(
			$response
		);
		$serverData = \json_decode(
			\json_encode(
				$responseXml->data
			),
			true
		);
		if (!\array_key_exists('element', $serverData)) {
			return [];
		} else {
			return isset($serverData['element'][0]) ?
				\array_column($serverData['element'], 'id', 'url') :
				\array_column($serverData, 'id', 'url');
		}
	}

	/**
	 * @BeforeScenario
	 *
	 * @return void
	 */
	public function prepareParametersBeforeScenario() {
		$user = $this->featureContext->getCurrentUser();
		$this->featureContext->setCurrentUser($this->featureContext->getAdminUsername());
		$previousServer = $this->featureContext->getCurrentServer();
		foreach (['LOCAL', 'REMOTE'] as $server) {
			if (($server === 'LOCAL') || $this->featureContext->federatedServerExists()) {
				$this->featureContext->usingServer($server);
				$this->featureContext->resetAppConfigs();
				$result = SetupHelper::runOcc(
					['config:list', '--private'], $this->featureContext->getAdminUsername(),
					$this->featureContext->getAdminPassword(), $this->featureContext->getBaseUrl(), $this->featureContext->getOcPath()
				);
				$this->savedConfigList[$server] = \json_decode($result['stdOut'], true);
			}
		}
		$this->featureContext->usingServer($previousServer);
		$this->featureContext->setCurrentUser($user);
	}

	/**
	 * Before Scenario to Save trusted Servers
	 *
	 * @BeforeScenario @federation-app-required
	 *
	 * @return void
	 */
	public function setInitialTrustedServersBeforeScenario() {
		$this->initialTrustedServer = [
			'LOCAL' => $this->getTrustedServers(),
			'REMOTE' => $this->getTrustedServers('REMOTE')
		];
	}

	/**
	 * After Scenario. restore trusted servers
	 *
	 * @AfterScenario @federation-app-required
	 *
	 * @return void
	 */
	public function restoreTrustedServersAfterScenario() {
		$this->restoreTrustedServers('LOCAL');
		if ($this->featureContext->federatedServerExists()) {
			$this->restoreTrustedServers('REMOTE');
		}
	}

	/**
	 * After Scenario. restore trusted servers
	 *
	 * @param string $server 'LOCAL'/'REMOTE'
	 *
	 * @return void
	 */
	public function restoreTrustedServers($server) {
		$currentTrustedServers = $this->getTrustedServers($server);
		foreach (\array_diff($currentTrustedServers, $this->initialTrustedServer[$server]) as $url => $id) {
			$this->theAdministratorDeletesUrlFromTrustedServersUsingTheTestingApi($url);
		}
		foreach (\array_diff($this->initialTrustedServer[$server], $currentTrustedServers) as $url => $id) {
			$this->theAdministratorAddsUrlAsTrustedServerUsingTheTestingApi($url);
		}
	}

	/**
	 * @AfterScenario
	 *
	 * @return void
	 */
	public function restoreParametersAfterScenario() {
		$this->featureContext->authContext->deleteTokenAuthEnforcedAfterScenario();
		$user = $this->featureContext->getCurrentUser();
		$this->featureContext->setCurrentUser($this->featureContext->getAdminUsername());
		$this->featureContext->runFunctionOnEveryServer([$this, 'restoreParameters']);
		$this->featureContext->setCurrentUser($user);
	}

	/**
	 * @BeforeScenario
	 *
	 * @param BeforeScenarioScope $scope
	 *
	 * @return void
	 */
	public function setUpScenario(BeforeScenarioScope $scope) {
		// Get the environment
		$environment = $scope->getEnvironment();
		// Get all the contexts you need in this context
		$this->featureContext = $environment->getContext('FeatureContext');
	}

	/**
	 * restore settings of the system and delete new settings that were created in the test runs
	 *
	 * @param string $server LOCAL|REMOTE
	 *
	 * @return void
	 *
	 * @throws \Exception
	 *
	 */
	private function restoreParameters($server) {
		if (\key_exists($this->featureContext->getBaseUrl(), $this->savedCapabilitiesChanges)) {
			$this->modifyAppConfigs($this->savedCapabilitiesChanges[$this->featureContext->getBaseUrl()]);
		}
		$result = SetupHelper::runOcc(
			['config:list'], $this->featureContext->getAdminUsername(),
			$this->featureContext->getAdminPassword(), $this->featureContext->getBaseUrl(),
			$this->featureContext->getOcPath()
		);
		$currentConfigList = \json_decode($result['stdOut'], true);
		foreach ($currentConfigList['system'] as $configKey => $configValue) {
			if (!\array_key_exists(
				$configKey, $this->savedConfigList[$server]['system']
			)
			) {
				SetupHelper::runOcc(
					['config:system:delete', $configKey],
					$this->featureContext->getAdminUsername(),
					$this->featureContext->getAdminPassword(),
					$this->featureContext->getBaseUrl(),
					$this->featureContext->getOcPath()
				);
			}
		}
		foreach ($this->savedConfigList[$server]['system'] as $configKey => $configValue) {
			if (!\array_key_exists($configKey, $currentConfigList["system"])
				|| $currentConfigList["system"][$configKey] !== $this->savedConfigList[$server]['system'][$configKey]
			) {
				SetupHelper::runOcc(
					['config:system:set', "--type=json", "--value=" . \json_encode($configValue), $configKey],
					$this->featureContext->getAdminUsername(),
					$this->featureContext->getAdminPassword(),
					$this->featureContext->getBaseUrl(),
					$this->featureContext->getOcPath()
				);
			}
		}
		foreach ($currentConfigList['apps'] as $appName => $appSettings) {
			foreach ($appSettings as $configKey => $configValue) {
				//only check if the app was there in the original configuration
				if (\array_key_exists($appName, $this->savedConfigList[$server]['apps'])
					&& !\array_key_exists(
						$configKey, $this->savedConfigList[$server]['apps'][$appName]
					)
				) {
					SetupHelper::runOcc(
						['config:app:delete', $appName, $configKey],
						$this->featureContext->getAdminUsername(),
						$this->featureContext->getAdminPassword(),
						$this->featureContext->getBaseUrl(),
						$this->featureContext->getOcPath()
					);
				} elseif (\array_key_exists($appName, $this->savedConfigList[$server]['apps'])
					&& \array_key_exists($configKey, $this->savedConfigList[$server]['apps'][$appName])
					&& $this->savedConfigList[$server]['apps'][$appName][$configKey] !== $configValue
				) {
					// Do not accidentally disable apps here (perhaps too early)
					// That is done in Provisioning.php restoreAppEnabledDisabledState()
					if ($configKey !== "enabled") {
						SetupHelper::runOcc(
							[
								'config:app:set',
								$appName,
								$configKey,
								"--value=" . $this->savedConfigList[$server]['apps'][$appName][$configKey]
							],
							$this->featureContext->getAdminUsername(),
							$this->featureContext->getAdminPassword(),
							$this->featureContext->getBaseUrl(),
							$this->featureContext->getOcPath()
						);
					}
				}
			}
		}
	}
}
