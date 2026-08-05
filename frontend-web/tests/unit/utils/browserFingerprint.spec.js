jest.mock("fingerprintjs2", () => ({
  get: jest.fn(),
  x64hash128: jest.fn(() => "generated-browser-id"),
}));

import Fingerprint2 from "fingerprintjs2";
import { resolveBrowserId } from "@/utils/browserFingerprint";

describe("resolveBrowserId", () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it("waits for the fingerprint callback before resolving", async () => {
    const storage = {
      getItem: jest.fn(() => null),
      setItem: jest.fn(),
    };
    let resolved = false;
    const promise = resolveBrowserId(storage).then((value) => {
      resolved = true;
      return value;
    });

    await Promise.resolve();
    expect(resolved).toBe(false);

    const callback = Fingerprint2.get.mock.calls[0][0];
    callback([{ value: "UA NetType/WIFI" }, { value: "screen" }]);

    await expect(promise).resolves.toBe("generated-browser-id");
    expect(Fingerprint2.x64hash128).toHaveBeenCalledWith("UA screen", 31);
    expect(storage.setItem).toHaveBeenCalledWith(
      "browserId",
      "generated-browser-id"
    );
  });

  it("uses the cached id only when fingerprint generation throws", async () => {
    Fingerprint2.get.mockImplementationOnce(() => {
      throw new Error("fingerprint unavailable");
    });
    const storage = {
      getItem: jest.fn(() => "cached-browser-id"),
      setItem: jest.fn(),
    };

    await expect(resolveBrowserId(storage)).resolves.toBe("cached-browser-id");
    expect(storage.setItem).not.toHaveBeenCalled();
  });

  it("uses the cached id when hashing throws in the fingerprint callback", async () => {
    Fingerprint2.x64hash128.mockImplementationOnce(() => {
      throw new Error("hash unavailable");
    });
    const storage = {
      getItem: jest.fn(() => "cached-browser-id"),
      setItem: jest.fn(),
    };
    const promise = resolveBrowserId(storage);
    const callback = Fingerprint2.get.mock.calls[0][0];

    callback([{ value: "UA" }]);

    await expect(promise).resolves.toBe("cached-browser-id");
    expect(storage.getItem).toHaveBeenCalledWith("browserId");
  });

  it("propagates a browser ID storage failure", async () => {
    Fingerprint2.get.mockImplementationOnce((callback) => {
      callback([{ value: "UA" }]);
    });
    const storage = {
      getItem: jest.fn(() => "cached-browser-id"),
      setItem: jest.fn(() => {
        throw new Error("storage unavailable");
      }),
    };

    await expect(resolveBrowserId(storage)).rejects.toThrow(
      "storage unavailable"
    );
    expect(storage.getItem).not.toHaveBeenCalled();
  });
});
