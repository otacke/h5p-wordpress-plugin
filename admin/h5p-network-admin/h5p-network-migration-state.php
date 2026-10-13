<?php

/**
 * H5P_Network_Migration_State
 *
 * State of a batched, resumable migration, kept in a site option between the requests that run its batches.
 * @package H5P
 * @since 1.18.0
 */
class H5P_Network_Migration_State {

  /**
   * Name of the site option holding the state.
   *
   * @var string
   */
  private $option;

  /**
   * State of a migration that has not started, with at least 'phase' and 'offset'.
   *
   * @var array
   */
  private $initial;

  /**
   * @param string $option  Name of the site option holding the state.
   * @param array  $initial State of a migration that has not started, with at least 'phase' and 'offset'.
   */
  public function __construct($option, array $initial) {
    $this->option = $option;
    $this->initial = $initial;
  }

  /**
   * Get the stored state, or the initial state if none is stored.
   *
   * @return array
   */
  public function get() {
    $state = get_site_option($this->option, null);

    return (is_array($state) && isset($state['phase'])) ? $state : $this->initial;
  }

  /**
   * Store the state.
   *
   * @param array $state
   */
  public function set($state) {
    update_site_option($this->option, $state);
  }

  /**
   * Forget the stored state, so the next migration starts over instead of resuming.
   */
  public function clear() {
    delete_site_option($this->option);
  }

  /**
   * Whether a migration is in flight: started and neither finished nor cleared.
   *
   * @return bool
   */
  public function exists() {
    return get_site_option($this->option, null) !== null;
  }
}
