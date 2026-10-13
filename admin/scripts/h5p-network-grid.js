/**
 * Keyboard navigation of the library grids on the network H5P Management page (WAI-ARIA APG data grid pattern).
 *
 * window.H5PNetworkGrid.makeNavigable(grid) adds it to a grid rendered server-side.
 */
(() => {
  /**
   * Add APG grid keyboard navigation with a roving tabindex.
   *
   * Cells holding a button delegate focus and keys to the button, the only
   * focusable child a cell can have.
   *
   * @param {HTMLElement} grid
   *
   * @return {function(HTMLElement)} Focus a data cell of the grid.
   */
  const makeNavigable = (grid) => {
    // The first row is the header and stays out of navigation.
    const cells = [...grid.querySelectorAll('[role="row"]')]
      .slice(1)
      .flatMap(row => [...row.children]);
    const columns = cells.length ? cells[0].parentElement.children.length : 0;
    const targets = cells.map(cell => cell.querySelector('button') || cell);

    const focusTarget = (index) => {
      targets.forEach((target, i) => target.setAttribute('tabindex', i === index ? '0' : '-1'));
      targets[index].focus();
    };

    targets.forEach((target, index) => {
      target.addEventListener('keydown', (event) => {
        let next;

        switch (event.key) {
          case 'ArrowLeft':
            next = index - 1;
            break;
          case 'ArrowRight':
            next = index + 1;
            break;
          case 'ArrowUp':
            next = index - columns;
            break;
          case 'ArrowDown':
            next = index + columns;
            break;
          case 'PageUp':
            next = index - 10 * columns;
            break;
          case 'PageDown':
            next = index + 10 * columns;
            break;
          case 'Home':
            next = event.ctrlKey || event.metaKey
              ? 0
              : Math.floor(index / columns) * columns;
            break;
          case 'End':
            next = event.ctrlKey || event.metaKey
              ? cells.length - 1
              : Math.floor(index / columns) * columns + columns - 1;
            break;
          default:
            return;
        }

        if (next < 0 || next >= cells.length) {
          return;
        }

        event.preventDefault();
        focusTarget(next);
      });
    });

    // Buttons are in the tab sequence by default, so all but the first target are taken out.
    targets.forEach((target, i) => target.setAttribute('tabindex', i === 0 ? '0' : '-1'));

    return (cell) => {
      const index = cells.indexOf(cell);
      if (index !== -1) {
        focusTarget(index);
      }
    };
  };

  window.H5PNetworkGrid = {makeNavigable};
})();
