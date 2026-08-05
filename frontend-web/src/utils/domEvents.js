export function bindContextMenu(target, showMenu) {
  const listener = (event) => {
    event.preventDefault();
    showMenu(event);
  };
  target.addEventListener("contextmenu", listener);
  return () => target.removeEventListener("contextmenu", listener);
}
