const fs = require("fs");
const os = require("os");
const path = require("path");
const { execFileSync } = require("child_process");

const projectRoot = path.resolve(__dirname, "../../..");
const scriptPath = path.join(projectRoot, "build/write-build-meta.js");

describe("build metadata", () => {
  it("writes current single-web metadata to the chosen output directory", () => {
    const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), "build-meta-"));
    const positionalOutputDir = path.join(tempRoot, "dist/web");
    const customOutputDir = path.join(tempRoot, "custom-output");

    fs.mkdirSync(positionalOutputDir, { recursive: true });
    fs.mkdirSync(customOutputDir, { recursive: true });

    try {
      execFileSync(
        process.execPath,
        [scriptPath, positionalOutputDir, "web"],
        {
          cwd: projectRoot,
          env: {
            ...process.env,
            OUTPUT_DIR: customOutputDir
          }
        }
      );

      const customMetadataPath = path.join(
        customOutputDir,
        "build-meta.json"
      );

      expect(fs.existsSync(customMetadataPath)).toBe(true);
      expect(
        fs.existsSync(path.join(positionalOutputDir, "build-meta.json"))
      ).toBe(false);
      const metadata = JSON.parse(
        fs.readFileSync(customMetadataPath, "utf8")
      );

      expect(Object.keys(metadata).sort()).toEqual([
        "builtAt",
        "gitSha",
        "variant"
      ]);
      expect(metadata.variant).toBe("web");
      expect(metadata.gitSha).toBe(
        execFileSync("git", ["rev-parse", "HEAD"], {
          cwd: projectRoot,
          encoding: "utf8"
        }).trim()
      );
      expect(new Date(metadata.builtAt).toISOString()).toBe(metadata.builtAt);
    } finally {
      fs.rmdirSync(tempRoot, { recursive: true });
    }
  });
});
