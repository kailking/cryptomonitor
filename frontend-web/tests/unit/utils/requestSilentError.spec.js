let mockResponseFulfilled;
let mockResponseRejected;

const mockMessage = jest.fn();
const mockConfirm = jest.fn();
const mockDispatch = jest.fn();
const mockReload = jest.fn();

jest.mock("axios", () => ({
  create: jest.fn(() => ({
    interceptors: {
      request: {
        use: jest.fn()
      },
      response: {
        use: jest.fn((fulfilled, rejected) => {
          mockResponseFulfilled = fulfilled;
          mockResponseRejected = rejected;
        })
      }
    }
  }))
}));

jest.mock("element-ui", () => ({
  Message: mockMessage,
  MessageBox: {
    confirm: mockConfirm
  }
}));

jest.mock("@/store", () => ({
  getters: {
    token: ""
  },
  dispatch: mockDispatch
}));

jest.mock("@/utils/auth", () => ({
  getToken: jest.fn()
}));

function expectOriginalRejection(promise, originalError) {
  return promise.then(
    () => {
      throw new Error("expected request interceptor to reject");
    },
    rejection => {
      if (originalError === undefined) {
        expect(rejection).toBeInstanceOf(Error);
      } else {
        expect(rejection).toBe(originalError);
      }
    }
  );
}

describe("request silentError option", () => {
  let originalLocation;

  beforeAll(() => {
    originalLocation = global.location;
    delete global.location;
    global.location = { reload: mockReload };
    require("@/utils/request");
  });

  afterAll(() => {
    delete global.location;
    global.location = originalLocation;
  });

  beforeEach(() => {
    jest.clearAllMocks();
    mockDispatch.mockResolvedValue(undefined);
  });

  test("suppresses the toast but preserves a backend-code rejection", async() => {
    const response = {
      config: { silentError: true },
      data: { code: 500, message: "radar temporarily unavailable" }
    };

    await expectOriginalRejection(mockResponseFulfilled(response));

    expect(mockMessage).not.toHaveBeenCalled();
    expect(mockConfirm).not.toHaveBeenCalled();
  });

  test("suppresses the toast but preserves the original transport rejection", async() => {
    const error = {
      config: { silentError: true },
      response: { status: 503, data: { message: "source unavailable" } },
      message: "transport failure"
    };

    await expectOriginalRejection(mockResponseRejected(error), error);

    expect(mockMessage).not.toHaveBeenCalled();
    expect(mockConfirm).not.toHaveBeenCalled();
  });

  test("keeps the existing toast behavior when silentError is absent", async() => {
    const error = {
      response: { status: 503, data: { message: "source unavailable" } },
      message: "transport failure"
    };

    await expectOriginalRejection(mockResponseRejected(error), error);

    expect(mockMessage).toHaveBeenCalledWith({
      message: "source unavailable",
      type: "error",
      duration: 5 * 1000
    });
  });

  test("does not bypass token-expiry handling when the toast is silent", async() => {
    mockConfirm.mockImplementation(() => Promise.resolve());
    const response = {
      config: { silentError: true },
      data: { code: 50014, message: "token expired" }
    };

    await expectOriginalRejection(mockResponseFulfilled(response));
    await Promise.resolve();
    await Promise.resolve();

    expect(mockMessage).not.toHaveBeenCalled();
    expect(mockConfirm).toHaveBeenCalledTimes(1);
    expect(mockDispatch).toHaveBeenCalledWith("user/resetToken");
    expect(mockReload).toHaveBeenCalledTimes(1);
  });
});
