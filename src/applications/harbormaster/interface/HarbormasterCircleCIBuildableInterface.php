<?php

/**
 * Support for CircleCI.
  */
  interface HarbormasterCircleCIBuildableInterface {

  public function getCircleCIGitHubRepositoryURI();
    public function getCircleCIBuildIdentifier();

}