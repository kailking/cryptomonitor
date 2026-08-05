import { bindContextMenu } from "@/utils/domEvents";

describe("bindContextMenu", () => {
  it("removes the exact listener that was added", () => {
    const target = {
      addEventListener: jest.fn(),
      removeEventListener: jest.fn(),
    };
    const showMenu = jest.fn();
    const unbind = bindContextMenu(target, showMenu);
    const listener = target.addEventListener.mock.calls[0][1];
    const event = { preventDefault: jest.fn() };

    listener(event);
    expect(event.preventDefault).toHaveBeenCalled();
    expect(showMenu).toHaveBeenCalledWith(event);

    unbind();
    expect(target.removeEventListener).toHaveBeenCalledWith(
      "contextmenu",
      listener
    );
  });
});
