<?php

final class HarbormasterCircleCIBuildStepImplementation
    extends HarbormasterBuildStepImplementation {

    public function getName() {
        return pht('Build with CircleCI');
    }

    public function getGenericDescription() {
        return pht('Trigger a build in CircleCI.');
    }

    public function getBuildStepGroupKey() {
        return HarbormasterExternalBuildStepGroup::GROUPKEY;
    }

    public function getDescription() {
        return pht('Run a build in CircleCI.');
    }

    public function getEditInstructions() {
        $hook_uri = '/harbormaster/hook/circleci/';
        $hook_uri = PhabricatorEnv::getProductionURI($hook_uri);

        return pht(<<<EOTEXT
WARNING: This build step is new and experimental!

To build **revisions** with CircleCI, they must:

  - belong to a tracked repository;
    - the repository must have a Staging Area configured;
      - the Staging Area must be hosted on GitHub; and
        - you must configure the webhook described below.

To build **commits** with CircleCI, they must:

  - belong to a repository that is being imported from GitHub; and
    - you must configure the webhook described below.

Webhook Configuration
=====================

Add this webhook to your `circle.yml` file to make CircleCI report results
to Harbormaster. Until you install this hook, builds will hang waiting for
a response from CircleCI.

```lang=yml
notify:
  webhooks:
      - url: %s
      ```

Environment
===========

These variables will be available in the build environment:

| Variable | Description |
|----------|-------------|
| `HARBORMASTER_BUILD_TARGET_PHID` | PHID of the Build Target.

EOTEXT
    ,
        $hook_uri);
    }

    public static function getGitHubPath($uri) {
        $uri_object = new PhutilURI($uri);
        $domain = $uri_object->getDomain();

        if (!strlen($domain)) {
            $uri_object = new PhutilGitURI($uri);
            $domain = $uri_object->getDomain();
        }

        $domain = phutil_utf8_strtolower($domain);
        switch ($domain) {
        case 'github.com':
        case 'www.github.com':
            return $uri_object->getPath();
        default:
            return null;
        }
    }

    public function execute(
        HarbormasterBuild $build,
        HarbormasterBuildTarget $build_target) {
        $viewer = PhabricatorUser::getOmnipotentUser();

        $buildable = $build->getBuildable();

        $object = $buildable->getBuildableObject();
        $object_phid = $object->getPHID();
        if (!($object instanceof HarbormasterCircleCIBuildableInterface)) {
            throw new Exception(
                pht(
                              'Object ("%s") does not implement interface "%s". Only objects '.
                              'which implement this interface can be built with CircleCI.',
                              $object_phid,
                              'HarbormasterCircleCIBuildableInterface'));
        }

        $github_uri = $object->getCircleCIGitHubRepositoryURI();
        $build_identifier = "refs/heads/phabricator_diff_branch_".$object->getID();
        //$object->getCircleCIBuildIdentifier(); //was above /\

        $path = self::getGitHubPath($github_uri);
        if ($path === null) {
            throw new Exception(
                pht(
                              'Object ("%s") claims "%s" is a GitHub repository URI, but the '.
                              'domain does not appear to be GitHub.',
                              $object_phid,
                              $github_uri));
        }

        $path_parts = trim($path, '/');
        $path_parts = explode('/', $path_parts);
        if (count($path_parts) < 2) {
            throw new Exception(
                pht(
                              'Object ("%s") claims "%s" is a GitHub repository URI, but the '.
                                        'path ("%s") does not have enough components (expected at least '.
                              'two).',
                              $object_phid,
                              $github_uri,
                              $path));
        }

        list($github_namespace, $github_name) = $path_parts;
        $github_name = preg_replace('(\\.git$)', '', $github_name);

        $credential_phid = $this->getSetting('circle-token');
        $circle_api_token = id(new PassphraseCredentialQuery())
                   ->setViewer($viewer)
                   ->withPHIDs(array($credential_phid))
                   ->needSecrets(true)
                   ->executeOne();
        if (!$circle_api_token) {
            throw new Exception(
                pht(
                    'Unable to load API token ("%s")!',
                    $credential_phid));
        }

        // When we pass "revision", the branch is ignored (and does not even need
        // to exist), and only shows up in the UI. Use a cute string which will
        // certainly never break anything or cause any kind of problem.
        $ship = "\xF0\x9F\x9A\xA2";
        $branch = "{$ship}Harbormaster";


        //COPY TAG TO BRANCH START
        $commitSha = $this->getRefOfTag($build, $build_target,
                                        $github_namespace, $github_name,
                                        $object->getCircleCIBuildIdentifier());
        $this->copyTagToBranch($build, $build_target,
                               $github_namespace, $github_name,
                               $build_identifier, $commitSha);
        //COPY TAG TO BRANCH END


        //TRIGGER CIRCLE START
        $token = $circle_api_token->getSecret()->openEnvelope();
        $parts = array(
            'https://circleci.com/api/v1/project',
            phutil_escape_uri($github_namespace),
            phutil_escape_uri($github_name),
            'tree',
            "{$branch}?circle-token={$token}",
        );

        $uri = implode('/', $parts);
        phlog("CIRCLE TRIGGER URL: ".$uri);

        $objectbranch = "";
        if ($object instanceof DifferentialDiff) {
            $objectbranch = $object->getBranch();
        }
        
        $data_structure = array(
            'revision' => $build_identifier,
            'build_parameters' => array(
                'PHABRICATOR_BRANCH' => $objectbranch,
                'HARBORMASTER_BUILD_TARGET_PHID' => $build_target->getPHID(),
            ),
        );

        $json_data = phutil_json_encode($data_structure);
        phlog("CIRCLE TRIGGER DAT: ".$json_data);

        $future = id(new HTTPSFuture($uri, $json_data))
                ->setMethod('POST')
                ->addHeader('Content-Type', 'application/json')
                ->addHeader('Accept', 'application/json')
                ->setTimeout(60);

        $this->resolveFutures(
            $build,
            $build_target,
            array($future));
        //TRIGGER CIRCLE END

        $this->logHTTPResponse($build, $build_target, $future, pht('CircleCI'));

        list($status, $body) = $future->resolve();
        if ($status->isError()) {
            throw new HarbormasterBuildFailureException();
        }

        $response = phutil_json_decode($body);
        $build_uri = idx($response, 'build_url');
        if (!$build_uri) {
            throw new Exception(
                pht(
                    'CircleCI did not return a "%s"!',
                    'build_url'));
        }

        $target_phid = $build_target->getPHID();

        // Write an artifact to create a link to the external build in CircleCI.

        $api_method = 'harbormaster.createartifact';
        $api_params = array(
            'buildTargetPHID' => $target_phid,
            'artifactType' => HarbormasterURIArtifact::ARTIFACTCONST,
            'artifactKey' => 'circleci.uri',
            'artifactData' => array(
                'uri' => $build_uri,
                'name' => pht('View in CircleCI'),
                'ui.external' => true,
            ),
        );

        id(new ConduitCall($api_method, $api_params))
            ->setUser($viewer)
            ->execute();
    }

    public function copyTagToBranch(
        HarbormasterBuild $build,
        HarbormasterBuildTarget $build_target,
        $github_namespace,
        $github_name,
        $build_identifier,
        $commitSha) {

        $viewer = PhabricatorUser::getOmnipotentUser();
        $settings = $this->getSettings();
        $variables = $build_target->getVariables();

        $data_structure = array(
            "ref" => $build_identifier,
            "sha" => $commitSha
        );
        $json_data = phutil_json_encode($data_structure);

        $parts = array(
            'https://api.github.com/repos',
            phutil_escape_uri($github_namespace),
            phutil_escape_uri($github_name),
            'git/refs',
        );

        $uri = implode('/', $parts);
        //$uri = 'https://api.github.com/repos/restlessbandit/uirest/git/refs';
        $future = id(new HTTPSFuture($uri,
                                     $json_data))
                ->setMethod('POST')
                ->addHeader('User-Agent', 'Phabricator-Customized-Test-Runner')
                ->addHeader('Content-Type', 'application/json')
                ->addHeader('Accept', 'application/json')
                ->setTimeout(60);

        $credential_phid = $this->getSetting('github-credential');
        $key = PassphrasePasswordKey::loadFromPHID(
            $credential_phid,
            $viewer);
        $future->setHTTPBasicAuthCredentials(
            $key->getUsernameEnvelope()->openEnvelope(),
            $key->getPasswordEnvelope());

        $this->resolveFutures(
            $build,
            $build_target,
            array($future));

        list($status, $body, $headers) = $future->resolve();

        phlog("OMG DID IT WORK FOR POST?!: ". $status."; ".$body);

        $header_lines = array();

        // TODO: We don't currently preserve the entire "HTTP" response header, but
        // should. Once we do, reproduce it here faithfully.
        $status_code = $status->getStatusCode();
        $header_lines[] = "HTTP {$status_code}";

        foreach ($headers as $header) {
            list($head, $tail) = $header;
            $header_lines[] = "{$head}: {$tail}";
        }
        $header_lines = implode("\n", $header_lines);

        $build_target
            ->newLog($uri, 'http.head')
            ->append($header_lines);

        $build_target
            ->newLog($uri, 'http.body')
            ->append($body);

        if ($status->isError()) {
            throw new HarbormasterBuildFailureException();
        }
    }

    public function getRefOfTag(
        HarbormasterBuild $build,
        HarbormasterBuildTarget $build_target,
        $github_namespace,
        $github_name,
        $real_build_identifier) {

        $viewer = PhabricatorUser::getOmnipotentUser();
        $settings = $this->getSettings();
        $variables = $build_target->getVariables();

        $parts = array(
            'https://api.github.com/repos',
            phutil_escape_uri($github_namespace),
            phutil_escape_uri($github_name),
            'git',
            $real_build_identifier,
        );

        $uri = implode('/', $parts);
        //$uri = 'https://api.github.com/repos/restlessbandit/uirest/git/'.$real_build_identifier;
        $future = id(new HTTPSFuture($uri))
                ->setMethod('GET')
                ->addHeader('User-Agent','Phabricator-Customized-Test-Runner')
                ->setTimeout(60);

        $credential_phid = $this->getSetting('github-credential');
        $key = PassphrasePasswordKey::loadFromPHID(
            $credential_phid,
            $viewer);
        $future->setHTTPBasicAuthCredentials(
            $key->getUsernameEnvelope()->openEnvelope(),
            $key->getPasswordEnvelope());

        $this->resolveFutures(
            $build,
            $build_target,
            array($future));

        list($status, $body, $headers) = $future->resolve();

        $header_lines = array();

        // TODO: We don't currently preserve the entire "HTTP" response header, but
        // should. Once we do, reproduce it here faithfully.
        $status_code = $status->getStatusCode();
        $header_lines[] = "HTTP {$status_code}";

        foreach ($headers as $header) {
            list($head, $tail) = $header;
            $header_lines[] = "{$head}: {$tail}";
        }
        $header_lines = implode("\n", $header_lines);

        $build_target
            ->newLog($uri, 'http.head')
            ->append($header_lines);

        $build_target
            ->newLog($uri, 'http.body')
            ->append($body);

        if ($status->isError()) {
            throw new HarbormasterBuildFailureException();
        }

        $decoded_body = phutil_json_decode($body);
        $body_object = $decoded_body['object'];
        $sha = $body_object['sha'];

        phlog("SHA: ".$sha);

        return $sha;
    }

    public function deleteBranchAsBot(
        HarbormasterBuild $build,
        HarbormasterBuildTarget $build_target,
        $github_namespace,
        $github_name,
        $build_identifier) {
        //$build_identifier = "refs/heads/phabricator_diff_branch_1234";

        $viewer = PhabricatorUser::getOmnipotentUser();
        $variables = $build_target->getVariables();

        $parts = array(
            'https://api.github.com/repos',
            phutil_escape_uri($github_namespace),
            phutil_escape_uri($github_name),
            "git",
            $build_identifier
        );
        $uri = implode('/', $parts);
        $future = id(new HTTPSFuture($uri))
                ->setMethod('DELETE')
                ->addHeader('User-Agent', 'Phabricator-Customized-Test-Runner')
                ->addHeader('Content-Type', 'application/json')
                ->addHeader('Accept', 'application/json')
                ->setTimeout(60);

        $credential_phid = $this->getSetting('github-credential');
        $key = PassphrasePasswordKey::loadFromPHID(
            $credential_phid,
            $viewer);
        $future->setHTTPBasicAuthCredentials(
            $key->getUsernameEnvelope()->openEnvelope(),
            $key->getPasswordEnvelope());

        $this->resolveFutures(
            $build,
            $build_target,
            array($future));

        list($status, $body, $headers) = $future->resolve();

        phlog("OMG DID IT WORK FOR POST?!: ". $status."; ".$body);

        $header_lines = array();

        // TODO: We don't currently preserve the entire "HTTP" response header, but
        // should. Once we do, reproduce it here faithfully.
        $status_code = $status->getStatusCode();
        $header_lines[] = "HTTP {$status_code}";

        foreach ($headers as $header) {
            list($head, $tail) = $header;
            $header_lines[] = "{$head}: {$tail}";
        }
        $header_lines = implode("\n", $header_lines);

        $build_target
            ->newLog($uri, 'http.head')
            ->append($header_lines);

        $build_target
            ->newLog($uri, 'http.body')
            ->append($body);

        if ($status->isError()) {
            throw new HarbormasterBuildFailureException();
        }
    }



    public function getFieldSpecifications() {
        return array(
            'circle-token' => array(
                'name' => pht('CircleCI API Token'),
                'type' => 'credential',
                'credential.type'
                => PassphraseTokenCredentialType::CREDENTIAL_TYPE,
                'credential.provides'
                => PassphraseTokenCredentialType::PROVIDES_TYPE,
                'required' => true,
            ),
            'github-credential' => array(
                'name' => pht('Github Credentials'),
                'type' => 'credential',
                'credential.type'
                => PassphrasePasswordCredentialType::CREDENTIAL_TYPE,
                'credential.provides'
                => PassphrasePasswordCredentialType::PROVIDES_TYPE,
                'required' => true,
            ),
            'bot-user' => array(
                'name' => 'User To Close Step As',
                'type' => 'users',
                'limit' => 1,
                'required' => true,
            ),
        );
    }

    public function supportsWaitForMessage() {
        // NOTE: We always wait for a message, but don't need to show the UI
        // control since "Wait" is the only valid choice.
        return false;
    }

    public function shouldWaitForMessage(HarbormasterBuildTarget $target) {
        return true;
    }


    protected function logHTTPResponse(
        HarbormasterBuild $build,
        HarbormasterBuildTarget $build_target,
        BaseHTTPFuture $future,
        $label) {

        list($status, $body, $headers) = $future->resolve();

        $header_lines = array();

        // TODO: We don't currently preserve the entire "HTTP" response header, but
        // should. Once we do, reproduce it here faithfully.
        $status_code = $status->getStatusCode();
        $header_lines[] = "HTTP {$status_code}";

        foreach ($headers as $header) {
            list($head, $tail) = $header;
            $header_lines[] = "{$head}: {$tail}";
        }
        $header_lines = implode("\n", $header_lines);

        $build_target
            ->newLog($label, 'http.head')
            ->append($header_lines);

        $build_target
            ->newLog($label, 'http.body')
            ->append($body);
    }

}
