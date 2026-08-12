const fs = require("fs");
const path = require("path");

const projectRoot = path.resolve(__dirname, "../../..");

function read(relativePath) {
  return fs.readFileSync(path.join(projectRoot, relativePath), "utf8");
}

function parseEnv(relativePath) {
  return read(relativePath)
    .split(/\r?\n/)
    .map(line => line.trim())
    .filter(line => line && !line.startsWith("#"))
    .reduce((environment, line) => {
      const separator = line.indexOf("=");
      const key = line.slice(0, separator).trim();
      const rawValue = line.slice(separator + 1).trim();
      const value = rawValue.replace(/^(['"])(.*)\1$/, "$2");

      environment[key] = value;
      return environment;
    }, {});
}

describe("production packaging", () => {
  it("defines exactly one production frontend build contract", () => {
    const { scripts } = JSON.parse(read("package.json"));
    const buildScripts = Object.keys(scripts)
      .filter(script => script.startsWith("build:"))
      .reduce((selected, script) => {
        selected[script] = scripts[script];
        return selected;
      }, {});

    expect(buildScripts).toEqual({
      "build:web":
        "vue-cli-service build --mode web && node build/write-build-meta.js dist/web web",
      "build:prod": "npm run build:web",
      "build:stage": "vue-cli-service build --mode staging"
    });
    expect(scripts).not.toHaveProperty("build:web89");
    expect(scripts).not.toHaveProperty("build:all");
  });

  it("keeps one web production environment without a variant contract", () => {
    expect(parseEnv(".env.web")).toEqual({
      NODE_ENV: "production",
      ENV: "production",
      VUE_APP_BASE_API: "/api",
      OUTPUT_DIR: "dist/web"
    });
    expect(fs.existsSync(path.join(projectRoot, ".env.web89"))).toBe(false);
  });

  it("does not install or initialize the legacy mock API", () => {
    const mainSource = read("src/main.js");
    const packageJson = JSON.parse(read("package.json"));
    const packageLock = JSON.parse(read("package-lock.json"));

    expect(mainSource).not.toMatch(/mockXHR|require\(["']\.\.\/mock["']\)/);
    expect(packageJson.devDependencies).not.toHaveProperty("mockjs");
    expect(packageLock.dependencies).not.toHaveProperty("mockjs");
    expect(fs.existsSync(path.join(projectRoot, "mock"))).toBe(false);
  });

  it.each(["diff.vue", "diff_5.vue"])(
    "lazy loads chart components in %s",
    fileName => {
      const source = read(`src/views/quotation/${fileName}`);

      expect(source).not.toMatch(/import Kline from/);
      expect(source).not.toMatch(/import Depth from/);
      expect(source).toMatch(
        /Kline:\s*\(\)\s*=>\s*import\(["']@\/components\/kline\/index\.vue["']\)/
      );
      expect(source).toMatch(
        /Depth:\s*\(\)\s*=>\s*import\(["']@\/components\/depth\/index\.vue["']\)/
      );
    }
  );
});
