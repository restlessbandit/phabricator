<?php

final class HarbormasterCircleCIHookController
    extends HarbormasterController {

    public function shouldRequireLogin() {
        return false;
    }

    /**
     * @phutil-external-symbol class PhabricatorStartup
     */
    public function handleRequest(AphrontRequest $request) {
        $raw_body = PhabricatorStartup::getRawInput();
        $body = phutil_json_decode($raw_body);

        $payload = $body['payload'];

        $parameters = idx($payload, 'build_parameters');
        if (!$parameters) {
            $parameters = array();
        }

        $target_phid = idx($parameters, 'HARBORMASTER_BUILD_TARGET_PHID');

        // NOTE: We'll get callbacks here for builds we triggered, but also for
        // arbitrary builds the system executes for other reasons. So it's normal
	    // to get some notifications with no Build Target PHID. We just ignore
        // these under the assumption that they're routine builds caused by events
        // like branch updates.

        if ($target_phid) {
            $viewer = PhabricatorUser::getOmnipotentUser();
	        $target = id(new HarbormasterBuildTargetQuery())
                    ->setViewer($viewer)
			        ->withPHIDs(array($target_phid))
                    ->needBuildSteps(true)
                    ->executeOne();
            if ($target) {
                $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
                $target
                    ->newLog("circleci", 'http.callback.payload')
                    ->append(json_encode($payload));
                $this->updateTarget($target, $payload);
            }else{
                phlog("CircleCI Callback came with no target phid. Payload:".json_encode($payload));
            }
        }else{
            phlog("CircleCI Callback came with no target phid. Payload:".json_encode($payload));
        }

        $response = new AphrontWebpageResponse();
        $response->setContent(pht("Request OK\n"));
        return $response;
    }

    private function updateTarget(
        HarbormasterBuildTarget $target,
        array $payload) {

        $step = $target->getBuildStep();
        $impl = $step->getStepImplementation();

	    if (!($impl instanceof HarbormasterCircleCIBuildStepImplementation)) {
            throw new Exception(
                pht(
                    'Build target ("%s") has the wrong type of build step. Only '.
                    'CircleCI build steps may be updated via the CircleCI webhook.',
                    $target->getPHID()));
        }

        switch (idx($payload, 'status')) {
        case 'testtest':
        case 'fixed':
        case 'success':
            $target
                ->newLog("status", 'http.callback')
                ->append("PASS");
            $message_type = HarbormasterMessageType::MESSAGE_PASS;
            break;
        default:
            $target
                ->newLog("status", 'http.callback')
                ->append("FAIL");
            $message_type = HarbormasterMessageType::MESSAGE_FAIL;
            break;
        }

        $bot_user_phids = phutil_json_decode($impl->getSetting('bot-user'));
        $user = id(new PhabricatorPeopleQuery())
              ->setViewer(PhabricatorUser::getOmnipotentUser())
              ->withPhids($bot_user_phids)
              ->executeOne(); //$build->getInitiatorPHID()

        $build = $target->getBuild();
        $buildable_object = $build->getBuildable()->getBuildableObject();
        $repo = id(new PhabricatorRepositoryQuery())
             ->setViewer(PhabricatorUser::getOmnipotentUser())
             ->withPhids(array($buildable_object->getRepositoryPHID()))
             ->executeOne();


        //DELETE GITHUB BRANCH START
        $buildable_object_phid = $buildable_object->getPHID();
        $github_uri = $buildable_object->getCircleCIGitHubRepositoryURI();
        $build_identifier = "refs/heads/phabricator_diff_branch_".$buildable_object->getID();
        //$buildable_object->getCircleCIBuildIdentifier(); //was above /\

        $path = $impl::getGitHubPath($github_uri);
        if ($path === null) {
            throw new Exception(
                pht(
                    'Object ("%s") claims "%s" is a GitHub repository URI, but the '.
                    'domain does not appear to be GitHub.',
                    $buildable_object_phid,
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
                              $buildable_object_phid,
                              $github_uri,
                              $path));
        }

        list($github_namespace, $github_name) = $path_parts;
        $github_name = preg_replace('(\\.git$)', '', $github_name);

        $build_identifier = "refs/heads/phabricator_diff_branch_".$buildable_object->getID();
        $impl->deleteBranchAsBot($build, $target, $github_namespace, $github_name, $build_identifier);
        //DELETE GITHUB BRANCH FINISH

        $api_method = 'harbormaster.sendmessage';
        $api_params = array(
            'buildTargetPHID' => $target->getPHID(),
            'type' => $message_type,
        );
        id(new ConduitCall($api_method, $api_params))->setUser($user)->execute();
    }

}