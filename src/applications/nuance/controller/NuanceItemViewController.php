<?php

final class NuanceItemViewController extends NuanceController {

  public function handleRequest(AphrontRequest $request) {
    $viewer = $this->getViewer();
    $id = $request->getURIData('id');

    $item = id(new NuanceItemQuery())
      ->setViewer($viewer)
      ->withIDs(array($id))
      ->executeOne();
    if (!$item) {
      return new Aphront404Response();
    }

    $title = pht('Item %d', $item->getID());
    $name = $item->getDisplayName();

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(
      pht('Items'),
      $this->getApplicationURI('item/'));
    $crumbs->addTextCrumb($title);
    $crumbs->setBorder(true);

    $curtain = $this->buildCurtain($item);
    $content = $this->buildContent($item);
    $commands = $this->buildCommands($item);

    $timeline = $this->buildTransactionTimeline(
      $item,
      new NuanceItemTransactionQuery());

    $main = array(
      $commands,
      $content,
      $timeline,
    );

    $header = id(new PHUIHeaderView())
      ->setHeader($name);

    $view = id(new PHUITwoColumnView())
      ->setHeader($header)
      ->setCurtain($curtain)
      ->setMainColumn($main);

    return $this->newPage()
      ->setTitle($title)
      ->setCrumbs($crumbs)
      ->appendChild($view);
  }

  private function buildCurtain(NuanceItem $item) {
    $viewer = $this->getViewer();
    $id = $item->getID();

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $item,
      PhabricatorPolicyCapability::CAN_EDIT);

    $curtain = $this->newCurtainView($item);

    $curtain->addAction(
      id(new PhabricatorActionView())
        ->setName(pht('Manage Item'))
        ->setIcon('fa-cogs')
        ->setHref($this->getApplicationURI("item/manage/{$id}/")));

    $impl = $item->getImplementation();
    $impl->setViewer($viewer);

    foreach ($impl->getItemActions($item) as $action) {
      $curtain->addAction($action);
    }

    return $curtain;
  }

  private function buildContent(NuanceItem $item) {
    $viewer = $this->getViewer();
    $impl = $item->getImplementation();

    $impl->setViewer($viewer);
    return $impl->buildItemView($item);
  }

  private function buildCommands(NuanceItem $item) {
    $viewer = $this->getViewer();

    $commands = id(new NuanceItemCommandQuery())
      ->setViewer($viewer)
      ->withItemPHIDs(array($item->getPHID()))
      ->execute();
    $commands = msort($commands, 'getID');

    if (!$commands) {
      return null;
    }

    $rows = array();
    foreach ($commands as $command) {
      $rows[] = array(
        $command->getCommand(),
      );
    }

    $table = id(new AphrontTableView($rows))
      ->setHeaders(
        array(
          pht('Command'),
        ));

    return id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Pending Commands'))
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
      ->setTable($table);
  }

}
