// The OpenAPI document `tinvar openapi` writes (ADR 0155), held to the
// OpenAPI 3.1 schema itself, and every Schema Object in it to JSON Schema
// 2020-12, the dialect OpenAPI 3.1 speaks, each compiled against the whole
// document so every $ref in it resolves -- with the shop's API as the
// document: its operations at /_api, its receipt endpoint, their scopes and
// their refusals. Fixtures/openapi-3.1/schema.json is the published
// schema, https://spec.openapis.org/oas/3.1/schema/2022-10-07, as it is.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..', '..');
const schemas = join(here, 'Fixtures', 'openapi-3.1');

function ajv() {
  const ajv = new Ajv2020({ strict: false, allErrors: true });
  addFormats(ajv);

  return ajv;
}

/**
 * The OpenAPI 3.1 schema, its `$dynamicRef: "#meta"` -- which, unextended,
 * resolves to its own `$defs/schema` -- written as that `$ref`: Ajv follows
 * a dynamic reference there into unevaluatedProperties it then reports on
 * every Schema Object, which the schema does not mean.
 */
function validator() {
  const text = readFileSync(join(schemas, 'schema.json'), 'utf8').replaceAll(
    '"$dynamicRef": "#meta"',
    '"$ref": "#/$defs/schema"',
  );

  return ajv().compile(JSON.parse(text) as Record<string, unknown>);
}

/** Every Schema Object in the document, by where it is. */
function schemasIn(value: unknown, path = '#', found: [string, unknown][] = []): [string, unknown][] {
  if (typeof value !== 'object' || value === null) return found;
  for (const [key, child] of Object.entries(value)) {
    const at = `${path}/${key.replaceAll('~', '~0').replaceAll('/', '~1')}`;
    if (key === 'schema' || path === '#/components/schemas') found.push([at, child]);
    else schemasIn(child, at, found);
  }

  return found;
}

type Document = {
  openapi: string;
  paths: Record<
    string,
    Record<string, { security: Record<string, string[]>[]; responses: Record<string, unknown> }>
  >;
  components: {
    securitySchemes: Record<string, { type: string; scheme: string }>;
    schemas: Record<string, unknown>;
  };
};

const document = JSON.parse(
  execFileSync('php', [join(root, 'bin', 'tinvar'), 'openapi'], {
    cwd: root,
    encoding: 'utf8',
    timeout: 60_000,
  }),
) as Document;

describe('the OpenAPI document', () => {
  it('is a valid OpenAPI 3.1 document, every schema in it included', () => {
    const validate = validator();
    const valid = validate(document);

    expect(validate.errors ?? []).toEqual([]);
    expect(valid).toBe(true);
    expect(document.openapi).toBe('3.1.0');
  });

  it('holds every Schema Object to JSON Schema 2020-12, each $ref resolving in the document', () => {
    const checker = ajv();
    checker.addSchema({ ...document, $id: 'urn:tinvar:openapi' } as never);
    const found = schemasIn(document);

    expect(found.length).toBeGreaterThan(10);
    for (const [at, schema] of found) {
      expect(checker.validateSchema(schema as never), at).toBe(true);
      expect(() => checker.compile({ $ref: `urn:tinvar:openapi${at}` }), at).not.toThrow();
    }
  });

  it('describes the shop operations and endpoint marked #[RequiresScope], each with its scopes', () => {
    expect(Object.keys(document.paths)).toEqual([
      '/_api/orders.cancel',
      '/_api/orders.detail',
      '/_api/orders.yours',
      '/api/orders/{order}/receipt.txt',
    ]);
    expect(document.paths['/_api/orders.yours']?.post?.security).toEqual([{ apiToken: ['orders:read'] }]);
    expect(document.paths['/_api/orders.cancel']?.post?.security).toEqual([{ apiToken: ['orders:cancel'] }]);
    expect(document.components.securitySchemes.apiToken).toMatchObject({ type: 'http', scheme: 'bearer' });
  });

  it('names every refusal, and describes Data, enums and moments as codegen types them', () => {
    expect(Object.keys(document.paths['/_api/orders.detail']?.post?.responses ?? {})).toEqual([
      '200',
      '401',
      '403',
      '404',
      '422',
      '429',
      '500',
    ]);
    expect(document.components.schemas.OrderStatus).toEqual({
      type: 'string',
      enum: ['placed', 'packed', 'shipped', 'delivered', 'cancelled'],
    });
    expect(document.components.schemas.OrderSummaryData).toMatchObject({
      properties: {
        placedAt: { type: 'string', format: 'date-time' },
        status: { $ref: '#/components/schemas/OrderStatus' },
      },
    });
  });

  it('would be refused by the schema with a malformed path item', () => {
    const validate = validator();
    const broken = structuredClone(document) as unknown as { paths: Record<string, unknown> };
    broken.paths['/_api/orders.yours'] = { post: { responses: 'none' } };

    expect(validate(broken)).toBe(false);
  });
});
