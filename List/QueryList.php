<?php

namespace MADB\List;

/**
 * Stores the per-connection query tabs shown in the query list. Storage, access, and mutation behavior live in focused traits.
 */
class QueryList {

  use QueryListStorageTrait;
  use QueryListAccessTrait;
  use QueryListMutationTrait;

  private static $instance;

  private $fileName = 'queries.json';
  private $queryList = [];

}
