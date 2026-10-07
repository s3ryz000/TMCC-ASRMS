import { requestFormErrors } from './RequestDocumentModal';

describe('Request Document form (#93)', () => {
  test('the purpose is required and limited to 255 characters', () => {
    expect(requestFormErrors({ purpose: '', copies: 1 }).purpose).toMatch(/purpose/);
    expect(requestFormErrors({ purpose: '   ', copies: 1 }).purpose).toMatch(/purpose/);
    expect(requestFormErrors({ purpose: 'a'.repeat(256), copies: 1 }).purpose).toMatch(/255/);
  });

  test('copies are 1 to 10', () => {
    expect(requestFormErrors({ purpose: 'Employment', copies: 0 }).copies).toBeDefined();
    expect(requestFormErrors({ purpose: 'Employment', copies: 11 }).copies).toBeDefined();
    expect(requestFormErrors({ purpose: 'Employment', copies: '2.5' }).copies).toBeDefined();
    expect(requestFormErrors({ purpose: 'Employment', copies: '3' })).toEqual({});
  });
});
