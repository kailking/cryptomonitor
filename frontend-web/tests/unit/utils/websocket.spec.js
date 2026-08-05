import WebSocketManager from "@/utils/websocket";

describe("WebSocketManager", () => {
  beforeEach(() => jest.useFakeTimers());
  afterEach(() => jest.useRealTimers());

  it("does not reconnect after an intentional disconnect", () => {
    const manager = new WebSocketManager("wss://example.test", {
      reconnectDelay: 1000
    });
    manager.connect = jest.fn(() => Promise.resolve());
    manager.isIntentionallyClosed = false;
    manager.scheduleReconnect();
    manager.disconnect();

    jest.advanceTimersByTime(1000);
    expect(manager.connect).not.toHaveBeenCalled();
  });

  it("decodes only the bytes inside a typed-array view", async () => {
    const manager = new WebSocketManager("wss://example.test");
    const buffer = new Uint8Array([65, 66, 67, 68]).buffer;
    const view = new Uint8Array(buffer, 1, 2);
    manager.decodeArrayBuffer = jest.fn(() =>
      Promise.resolve({ text: "decoded", bytes: null })
    );

    await manager.decodeMessage(view);
    const received = new Uint8Array(
      manager.decodeArrayBuffer.mock.calls[0][0]
    );
    expect(Array.from(received)).toEqual([66, 67]);
  });
});
