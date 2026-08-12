const fs = require("fs");
const path = require("path");
const { parse, parseExpression } = require("@babel/parser");
const { compile, parseComponent } = require("vue-template-compiler");

const mockRefreshPlatformAddress = jest.fn();
const mockConfigPlatformAddress = jest.fn();
const mockCalcProfit = jest.fn();

jest.mock("@/api/table", () => ({
  getQuotationPrice: jest.fn(),
  getQuotationPricePlus: jest.fn(),
  getPlatformList: jest.fn(),
  getSymbolOption: jest.fn(),
  getWithdrawInfo: jest.fn(),
  refreshPlatformAddress: mockRefreshPlatformAddress,
  configPlatformAddress: mockConfigPlatformAddress,
  postCollect: jest.fn(),
  postRemark: jest.fn(),
}));

jest.mock("@/api/user", () => ({
  setFilter: jest.fn(),
  getFilter: jest.fn(),
  setPlatformFilter: jest.fn(),
  getPlatformFilter: jest.fn(),
  setCommonFilter: jest.fn(),
  getCommonFilter: jest.fn(),
  blockId: jest.fn(),
  getInfo: jest.fn(),
}));

jest.mock("@/utils/platform", () => {
  const actual = jest.requireActual("@/utils/platform");
  return {
    ...actual,
    calcProfit: mockCalcProfit,
  };
});

const Diff = require("@/views/quotation/diff.vue").default;
const Diff5 = require("@/views/quotation/diff_5.vue").default;

const pages = [
  {
    name: "diff",
    component: Diff,
    sourcePath: "src/views/quotation/diff.vue",
  },
  {
    name: "diff_5",
    component: Diff5,
    sourcePath: "src/views/quotation/diff_5.vue",
  },
];

function readSource(sourcePath) {
  return fs.readFileSync(path.resolve(process.cwd(), sourcePath), "utf8");
}

const MAIN_TABLE_OWNER = "handleHeaderDragend";
const RIGHT_TABLE_OWNER = "handleHeaderDragendRight";

function getTemplateSource(source) {
  const descriptor = parseComponent(source);
  if (!descriptor.template) {
    throw new Error("quotation component must have a template");
  }
  return descriptor.template.content.replace(/\r\n/g, "\n");
}

function getScriptSource(source) {
  const descriptor = parseComponent(source);
  if (!descriptor.script) {
    throw new Error("quotation component must have a script");
  }
  return descriptor.script.content.replace(/\r\n/g, "\n");
}

function unwrapExpression(node) {
  let current = node;
  while (current && current.type === "ParenthesizedExpression") {
    current = current.expression;
  }
  return current;
}

function isIdentifier(node, name) {
  const current = unwrapExpression(node);
  return current && current.type === "Identifier" && current.name === name;
}

function hasLiteralValue(node, value) {
  const current = unwrapExpression(node);
  return (
    current &&
    ["StringLiteral", "BooleanLiteral", "NumericLiteral"].includes(
      current.type
    ) &&
    current.value === value
  );
}

function isCall(node, calleeName, argumentMatchers) {
  const current = unwrapExpression(node);
  return (
    current &&
    current.type === "CallExpression" &&
    isIdentifier(current.callee, calleeName) &&
    current.arguments.length === argumentMatchers.length &&
    argumentMatchers.every((matcher, index) =>
      matcher(current.arguments[index])
    )
  );
}

function propertyName(property) {
  if (!property || property.computed) return null;
  if (property.key.type === "Identifier") return property.key.name;
  if (property.key.type === "StringLiteral") return property.key.value;
  return null;
}

function findObjectProperty(objectExpression, name) {
  if (!objectExpression || objectExpression.type !== "ObjectExpression") {
    return undefined;
  }
  return objectExpression.properties.find(
    (property) => propertyName(property) === name
  );
}

function isMemberPath(node, pathParts) {
  let current = unwrapExpression(node);
  for (let index = pathParts.length - 1; index >= 0; index -= 1) {
    if (
      !current ||
      current.type !== "MemberExpression" ||
      current.computed ||
      current.property.type !== "Identifier" ||
      current.property.name !== pathParts[index]
    ) {
      return false;
    }
    current = unwrapExpression(current.object);
  }
  return current && current.type === "ThisExpression";
}

function getComputedPermissionMethods(script) {
  const program = parse(script, { sourceType: "module" }).program;
  const exportDefault = program.body.find(
    (statement) => statement.type === "ExportDefaultDeclaration"
  );
  const computedProperty = exportDefault
    ? findObjectProperty(exportDefault.declaration, "computed")
    : undefined;
  const computed =
    computedProperty && computedProperty.type === "ObjectProperty"
      ? computedProperty.value
      : undefined;

  return {
    showMainProfitColumn: findObjectProperty(computed, "showMainProfitColumn"),
    canConfigurePlatformAddress: findObjectProperty(
      computed,
      "canConfigurePlatformAddress"
    ),
  };
}

function getOnlyReturnExpression(method) {
  if (!method || method.type !== "ObjectMethod") return undefined;
  const statements = method.body.body;
  if (statements.length !== 1 || statements[0].type !== "ReturnStatement") {
    return undefined;
  }
  return statements[0].argument;
}

function isExactPermissionCall(node, permission) {
  return isCall(node, "hasPermission", [
    (argument) => hasLiteralValue(argument, permission),
    (argument) =>
      isMemberPath(argument, ["$store", "getters", "permissions"]),
  ]);
}

const COMPUTED_PERMISSION_CONTRACT = {
  showMainProfitColumn: "quotation.profit.view",
  canConfigurePlatformAddress: "platform.address.configure",
};

function validateComputedPermissionContract(script) {
  const methods = getComputedPermissionMethods(script);
  return Object.entries(COMPUTED_PERMISSION_CONTRACT).flatMap(
    ([methodName, permission]) => {
      const expression = getOnlyReturnExpression(methods[methodName]);
      return isExactPermissionCall(expression, permission)
        ? []
        : [
            `${methodName} must return only hasPermission(${permission}, this.$store.getters.permissions)`,
          ];
    }
  );
}

function mutateComputedPermission(script, methodName, extraPermission) {
  const method = getComputedPermissionMethods(script)[methodName];
  const expression = getOnlyReturnExpression(method);
  if (!expression) {
    throw new Error(`${methodName} return expression is missing`);
  }
  const original = script.slice(expression.start, expression.end);
  return (
    script.slice(0, expression.start) +
    `(${original} || hasPermission("${extraPermission}", this.$store.getters.permissions))` +
    script.slice(expression.end)
  );
}

function isVisibilityCall(node, listName) {
  return isCall(node, "isColumnVisible", [
    (argument) => isIdentifier(argument, listName),
    (argument) => hasLiteralValue(argument, "lossgiftfee"),
    (argument) => hasLiteralValue(argument, true),
  ]);
}

function isWidthCall(node, side) {
  return isCall(node, "getWidth", [
    (argument) => hasLiteralValue(argument, side),
    (argument) => hasLiteralValue(argument, "lossgiftfee"),
    (argument) => hasLiteralValue(argument, 130),
  ]);
}

function isExactProfitVisibility(node, listName) {
  const current = unwrapExpression(node);
  return (
    current &&
    current.type === "LogicalExpression" &&
    current.operator === "&&" &&
    isIdentifier(current.left, "showMainProfitColumn") &&
    isVisibilityCall(current.right, listName)
  );
}

function parseTemplateExpression(expression, description) {
  if (typeof expression !== "string" || expression.trim() === "") {
    throw new Error(`${description} expression is missing`);
  }
  return parseExpression(expression.trim());
}

function getTableColumns(template, prop) {
  const compiled = compile(template, { outputSourceRange: true });
  if (compiled.errors.length > 0) {
    throw new Error(`template compilation failed: ${compiled.errors.join(";")}`);
  }

  const columns = [];
  function visit(node, tableOwner) {
    if (!node) return;

    let owner = tableOwner;
    if (node.tag === "el-table") {
      const dragHandler = node.attrsMap && node.attrsMap["@header-dragend"];
      owner = [MAIN_TABLE_OWNER, RIGHT_TABLE_OWNER].includes(dragHandler)
        ? dragHandler
        : null;
    }

    if (
      node.tag === "el-table-column" &&
      node.attrsMap &&
      node.attrsMap.prop === prop
    ) {
      columns.push({
        owner,
        ifExpression: parseTemplateExpression(node.if, "profit v-if"),
        widthExpression: parseTemplateExpression(
          node.attrsMap[":width"],
          "profit width"
        ),
        start: node.start,
        end: node.end,
        ifAttributeRange: node.rawAttrsMap && node.rawAttrsMap["v-if"],
      });
    }

    (node.children || []).forEach((child) => visit(child, owner));
  }

  visit(compiled.ast, null);
  return columns;
}

function getProfitColumns(template) {
  return getTableColumns(template, "lossgiftfee");
}

function replaceColumnIfExpression(template, column, expression, quote = '"') {
  const attribute = column.ifAttributeRange;
  if (!attribute || !Number.isInteger(attribute.start) || !Number.isInteger(attribute.end)) {
    throw new Error("profit v-if source range is missing");
  }
  const nameStart = template.indexOf(attribute.name, attribute.start);
  if (nameStart < attribute.start || nameStart > attribute.end) {
    throw new Error("profit v-if name is outside its AST source range");
  }
  const equals = template.indexOf("=", nameStart + attribute.name.length);
  if (equals < 0 || equals > attribute.end) {
    throw new Error("profit v-if assignment is outside its AST source range");
  }
  let valueStart = equals + 1;
  while (/\s/.test(template[valueStart])) valueStart += 1;
  const originalQuote = template[valueStart];
  if (!["'", '"'].includes(originalQuote)) {
    throw new Error("profit v-if must use a quoted attribute value");
  }
  const valueEnd = template.indexOf(originalQuote, valueStart + 1);
  if (valueEnd < 0) {
    throw new Error("profit v-if closing quote is missing");
  }
  return (
    template.slice(0, nameStart) +
    `v-if=${quote}${expression}${quote}` +
    template.slice(valueEnd + 1)
  );
}

function validateProfitColumnContract(template) {
  const columns = getProfitColumns(template);
  const errors = [];

  if (columns.length !== 2) {
    errors.push(`expected exactly 2 profit columns, received ${columns.length}`);
  }

  const mainColumns = columns.filter(
    (column) => column.owner === MAIN_TABLE_OWNER
  );
  const rightColumns = columns.filter(
    (column) => column.owner === RIGHT_TABLE_OWNER
  );

  if (mainColumns.length !== 1) {
    errors.push(`expected 1 main profit column, received ${mainColumns.length}`);
  } else {
    if (!isWidthCall(mainColumns[0].widthExpression, "main")) {
      errors.push("main profit column must use the main width key");
    }
    if (!isExactProfitVisibility(mainColumns[0].ifExpression, "lists")) {
      errors.push(
        "main profit column must use only showMainProfitColumn and lists visibility"
      );
    }
  }

  if (rightColumns.length !== 1) {
    errors.push(
      `expected 1 right profit column, received ${rightColumns.length}`
    );
  } else {
    if (!isWidthCall(rightColumns[0].widthExpression, "right")) {
      errors.push("right profit column must use the right width key");
    }
    if (!isExactProfitVisibility(rightColumns[0].ifExpression, "lists_temp")) {
      errors.push(
        "right profit column must use showMainProfitColumn and lists_temp visibility"
      );
    }
  }

  return errors;
}

function evaluateExpression(node, context) {
  const current = unwrapExpression(node);
  if (current.type === "Identifier") {
    if (!Object.prototype.hasOwnProperty.call(context, current.name)) {
      throw new Error(`unsupported identifier: ${current.name}`);
    }
    return context[current.name];
  }
  if (
    ["StringLiteral", "BooleanLiteral", "NumericLiteral"].includes(
      current.type
    )
  ) {
    return current.value;
  }
  if (current.type === "LogicalExpression" && current.operator === "&&") {
    return (
      evaluateExpression(current.left, context) &&
      evaluateExpression(current.right, context)
    );
  }
  if (current.type === "CallExpression" && current.callee.type === "Identifier") {
    const callable = context[current.callee.name];
    if (typeof callable !== "function") {
      throw new Error(`unsupported call: ${current.callee.name}`);
    }
    return callable(
      ...current.arguments.map((argument) =>
        evaluateExpression(argument, context)
      )
    );
  }
  throw new Error(`unsupported expression type: ${current.type}`);
}

function getProfitColumnVisibility(component, template, permissions) {
  const columns = getProfitColumns(template);
  const storeContext = permissionContext(permissions);
  const context = {
    lists: [{ key: "lossgiftfee", ispass: true }],
    lists_temp: [{ key: "lossgiftfee", ispass: true }],
    showMainProfitColumn: component.computed.showMainProfitColumn.call(
      storeContext
    ),
    canConfigurePlatformAddress:
      component.computed.canConfigurePlatformAddress.call(storeContext),
    isColumnVisible: (...args) =>
      component.methods.isColumnVisible.call({}, ...args),
  };

  const mainColumn = columns.find(
    (column) => column.owner === MAIN_TABLE_OWNER
  );
  const rightColumn = columns.find(
    (column) => column.owner === RIGHT_TABLE_OWNER
  );

  return {
    main: evaluateExpression(mainColumn.ifExpression, context),
    right: evaluateExpression(rightColumn.ifExpression, context),
  };
}

function getUnrelatedColumnVisibility(component, template, permissions) {
  const columns = getTableColumns(template, "price_diff");
  const storeContext = permissionContext(permissions);
  const context = {
    lists: [{ key: "price_diff", ispass: true }],
    lists_temp: [{ key: "price_diff", ispass: true }],
    showMainProfitColumn: component.computed.showMainProfitColumn.call(
      storeContext
    ),
    isColumnVisible: (...args) =>
      component.methods.isColumnVisible.call({}, ...args),
  };

  const mainColumn = columns.find(
    (column) => column.owner === MAIN_TABLE_OWNER
  );
  const rightColumn = columns.find(
    (column) => column.owner === RIGHT_TABLE_OWNER
  );

  return {
    main: evaluateExpression(mainColumn.ifExpression, context),
    right: evaluateExpression(rightColumn.ifExpression, context),
  };
}

function getWalletMutationButtonOpeningTags(source) {
  const tags = [];
  const handlerPattern =
    /@click="(?:handlePlatformAddress\([^)]*\)|handleEditAddress\([^)]*\)|savePlatformAddressConfig)"/g;
  let match = handlerPattern.exec(source);

  while (match) {
    const start = source.lastIndexOf("<el-button", match.index);
    const end = source.indexOf(">", match.index);
    tags.push(source.slice(start, end + 1));
    match = handlerPattern.exec(source);
  }

  return tags;
}

function permissionContext(permissions, roles = []) {
  return {
    $store: {
      getters: {
        permissions,
        roles,
      },
    },
  };
}

describe.each(pages)("$name quotation permissions", ({ component, sourcePath }) => {
  let source;

  beforeAll(() => {
    source = readSource(sourcePath);
  });

  beforeEach(() => {
    jest.clearAllMocks();
  });

  it("shows both profit columns only with quotation.profit.view", () => {
    const template = getTemplateSource(source);
    expect(validateProfitColumnContract(template)).toEqual([]);
    expect(getProfitColumns(template)).toHaveLength(2);

    expect(getProfitColumnVisibility(component, template, [])).toEqual({
      main: false,
      right: false,
    });
    expect(
      getProfitColumnVisibility(component, template, ["quotation.profit.view"])
    ).toEqual({ main: true, right: true });
    expect(
      getProfitColumnVisibility(component, template, [
        "platform.address.configure",
      ])
    ).toEqual({ main: false, right: false });
    expect(
      getProfitColumnVisibility(component, template, [
        "quotation.profit.view",
        "platform.address.configure",
      ])
    ).toEqual({ main: true, right: true });
  });

  it("does not change unrelated main or right column preferences", () => {
    const template = getTemplateSource(source);

    expect(getUnrelatedColumnVisibility(component, template, [])).toEqual({
      main: true,
      right: true,
    });
    expect(
      getUnrelatedColumnVisibility(component, template, [
        "quotation.profit.view",
      ])
    ).toEqual({ main: true, right: true });
  });

  it("rejects missing or unrelated right permission gates and extra profit columns", () => {
    const template = getTemplateSource(source);
    const rightColumn = getProfitColumns(template).find(
      (column) => column.owner === RIGHT_TABLE_OWNER
    );
    const equivalentFormatting = replaceColumnIfExpression(
      template,
      rightColumn,
      ' ( showMainProfitColumn && isColumnVisible( lists_temp, "lossgiftfee", true ) ) ',
      "'"
    );
    expect(validateProfitColumnContract(equivalentFormatting)).toEqual([]);
    expect(getProfitColumnVisibility(component, equivalentFormatting, []).right).toBe(false);

    const missingGateMutant = replaceColumnIfExpression(
      equivalentFormatting,
      getProfitColumns(equivalentFormatting).find(
        (column) => column.owner === RIGHT_TABLE_OWNER
      ),
      'isColumnVisible(lists_temp, "lossgiftfee", true)',
      "'"
    );
    expect(validateProfitColumnContract(missingGateMutant)).toContain(
      "right profit column must use showMainProfitColumn and lists_temp visibility"
    );

    const rightGateMutant = replaceColumnIfExpression(
      equivalentFormatting,
      getProfitColumns(equivalentFormatting).find(
        (column) => column.owner === RIGHT_TABLE_OWNER
      ),
      'canConfigurePlatformAddress && isColumnVisible(lists_temp, "lossgiftfee", true)',
      "'"
    );
    expect(validateProfitColumnContract(rightGateMutant)).toContain(
      "right profit column must use showMainProfitColumn and lists_temp visibility"
    );
    expect(getProfitColumnVisibility(component, rightGateMutant, []).right).toBe(
      false
    );

    const rightColumnEnd = template.indexOf(">", rightColumn.end) + 1;
    const duplicateRightColumn = template.slice(
      rightColumn.start,
      rightColumnEnd
    );
    const thirdColumnMutant =
      template.slice(0, rightColumnEnd) +
      duplicateRightColumn +
      template.slice(rightColumnEnd);
    expect(getProfitColumns(thirdColumnMutant)).toHaveLength(3);
    expect(validateProfitColumnContract(thirdColumnMutant)).toContain(
      "expected exactly 2 profit columns, received 3"
    );
  });

  it("fails closed for missing or malformed permissions and admin roles", () => {
    const showMainProfitColumn = (permissions, roles = []) =>
      component.computed.showMainProfitColumn.call(
        permissionContext(permissions, roles)
      );
    const canConfigurePlatformAddress = (permissions, roles = []) =>
      component.computed.canConfigurePlatformAddress.call(
        permissionContext(permissions, roles)
      );

    expect(showMainProfitColumn([])).toBe(false);
    expect(showMainProfitColumn(["quotation.profit.view"])).toBe(true);
    expect(showMainProfitColumn(["platform.address.configure"])).toBe(false);
    expect(
      showMainProfitColumn([
        "quotation.profit.view",
        "platform.address.configure",
      ])
    ).toBe(true);
    expect(showMainProfitColumn([], ["admin"])).toBe(false);
    expect(showMainProfitColumn(undefined)).toBe(false);
    expect(showMainProfitColumn("quotation.profit.view")).toBe(false);

    expect(canConfigurePlatformAddress([])).toBe(false);
    expect(
      canConfigurePlatformAddress(["platform.address.configure"])
    ).toBe(true);
    expect(canConfigurePlatformAddress(["quotation.profit.view"])).toBe(false);
    expect(
      canConfigurePlatformAddress([
        "quotation.profit.view",
        "platform.address.configure",
      ])
    ).toBe(true);
    expect(canConfigurePlatformAddress([], ["admin"])).toBe(false);
    expect(canConfigurePlatformAddress(undefined)).toBe(false);
    expect(canConfigurePlatformAddress("platform.address.configure")).toBe(
      false
    );

    const script = getScriptSource(source);
    expect(validateComputedPermissionContract(script)).toEqual([]);
    expect(
      validateComputedPermissionContract(
        mutateComputedPermission(
          script,
          "showMainProfitColumn",
          "platform.address.configure"
        )
      )
    ).toContain(
      "showMainProfitColumn must return only hasPermission(quotation.profit.view, this.$store.getters.permissions)"
    );
    expect(
      validateComputedPermissionContract(
        mutateComputedPermission(
          script,
          "canConfigurePlatformAddress",
          "quotation.profit.view"
        )
      )
    ).toContain(
      "canConfigurePlatformAddress must return only hasPermission(platform.address.configure, this.$store.getters.permissions)"
    );
  });

  it("gates every duplicated wallet mutation button and the save button", () => {
    const mutationButtons = getWalletMutationButtonOpeningTags(source);
    expect(mutationButtons).toHaveLength(7);
    mutationButtons.forEach((button) => {
      expect(button).toContain("canConfigurePlatformAddress");
    });
  });

  it("guards wallet mutation methods before any caller-owned dereference", async () => {
    const warning = jest.fn();
    const denied = {
      canConfigurePlatformAddress: false,
      isAdmin: true,
      $message: { warning },
      platformAddressForm: new Proxy(
        {},
        {
          get() {
            throw new Error("form must not be read");
          },
        }
      ),
    };

    await expect(
      component.methods.handleEditAddress.call(denied, undefined, 1)
    ).resolves.toBeUndefined();
    await expect(
      component.methods.handlePlatformAddress.call(denied, undefined, 1)
    ).resolves.toBeUndefined();
    await expect(
      component.methods.savePlatformAddressConfig.call(denied)
    ).resolves.toBeUndefined();

    expect(warning).toHaveBeenCalledTimes(3);
    expect(mockRefreshPlatformAddress).not.toHaveBeenCalled();
    expect(mockConfigPlatformAddress).not.toHaveBeenCalled();
  });

  it("has no component-local role or user-info authorization path", () => {
    expect(source).not.toMatch(/\broles:\s*\[/);
    expect(component.computed.isAdmin).toBeUndefined();
    expect(component.methods.fetchUserInfo).toBeUndefined();
    expect(source).not.toContain("getInfo");
  });

  it("reads and writes widths through the unified page key", () => {
    const context = { pagePreferenceName: path.basename(sourcePath, ".vue") };
    const key = `crypto-monitor:unified:${context.pagePreferenceName}:main:width:remark`;
    localStorage.clear();
    localStorage.setItem(key, "168");

    expect(component.methods.getWidth.call(context, "main", "remark", 120)).toBe(
      168
    );
    component.methods.saveWidth.call(context, "main", 176, {
      property: "remark",
    });
    expect(localStorage.getItem(key)).toBe("176");
  });
});

describe("profit behavior boundaries", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.clear();
    mockCalcProfit.mockReturnValue("expected-profit");
  });

  it("keeps diff platform_fee persistence and calcProfit arguments", () => {
    const feeContext = {
      platformAll: [],
      platformAllTemp: [{ id: 1, val: 0.1 }],
      setLossFeeVisible: true,
    };
    Diff.methods.onSaveLossFee.call(feeContext);

    expect(localStorage.getItem("platform_fee")).toBe(
      JSON.stringify([{ id: 1, val: 0.1 }])
    );
    expect(
      Diff.methods.getLossGiftFee.call(
        {
          platformAll: [
            { id: "buy", val: 0.1 },
            { id: "sell", val: 0.2 },
          ],
        },
        {
          buy_platform: "buy",
          sell_platform: "sell",
          total_buy_price: 100.6,
          total_sell_price: 90.2,
          buy_price: 10,
          sell_price: 11,
        }
      )
    ).toBe("expected-profit");
    expect(mockCalcProfit).toHaveBeenCalledWith(90, 10, 11, 0.1, 0.2);
  });

  it("keeps diff_5 platform_fee persistence and calcProfit arguments", () => {
    const feeContext = {
      platformAll: [],
      platformAllTemp: [{ id: 2, val: 0.3 }],
      setLossFeeVisible: true,
    };
    Diff5.methods.onSaveLossFee.call(feeContext);

    expect(localStorage.getItem("platform_fee")).toBe(
      JSON.stringify([{ id: 2, val: 0.3 }])
    );
    expect(
      Diff5.methods.getLossGiftFee.call(
        {
          platformAll: [
            { id: "buy", val: 0.4 },
            { id: "sell", val: 0.5 },
          ],
        },
        {
          buy_platform: "buy",
          sell_platform: "sell",
          total_buy_plus: 120.8,
          total_sell_plus: 130.2,
          buy_price_plus: 20,
          sell_price_plus: 22,
        }
      )
    ).toBe("expected-profit");
    expect(mockCalcProfit).toHaveBeenCalledWith(121, 20, 22, 0.4, 0.5);
  });
});
