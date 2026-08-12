const fs = require("fs");
const path = require("path");
const { execFileSync } = require("child_process");

const positionalOutputDir = process.argv[2];
const variant = process.argv[3];
const outputDir = process.env.OUTPUT_DIR || positionalOutputDir;

if (!outputDir || !variant) {
  throw new Error(
    "Usage: node build/write-build-meta.js <outputDir> <variant>"
  );
}

const gitSha = execFileSync("git", ["rev-parse", "HEAD"], {
  encoding: "utf8"
}).trim();

const metadata = {
  variant,
  gitSha,
  builtAt: new Date().toISOString()
};

fs.writeFileSync(
  path.join(outputDir, "build-meta.json"),
  `${JSON.stringify(metadata, null, 2)}\n`,
  "utf8"
);
