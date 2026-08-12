import Fingerprint2 from "fingerprintjs2";

function generateBrowserId() {
  return new Promise((resolve, reject) => {
    try {
      Fingerprint2.get((components) => {
        try {
          const values = components.map((component, index) => {
            if (index === 0) {
              return component.value.replace(/\bNetType\/\w+\b/, "");
            }
            return component.value;
          });
          resolve(Fingerprint2.x64hash128(values.join(""), 31));
        } catch (error) {
          reject(error);
        }
      });
    } catch (error) {
      reject(error);
    }
  });
}

export async function resolveBrowserId(storage = window.localStorage) {
  let browserId;
  try {
    browserId = await generateBrowserId();
  } catch (error) {
    const cached = storage.getItem("browserId");
    if (cached) return cached;
    throw error;
  }
  storage.setItem("browserId", browserId);
  return browserId;
}
