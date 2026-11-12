"""Converte a planilha CRM_Perfeita_Visao.xlsx (abas Ligações e Clientes) no CSV do `php artisan clients:import`.

Uso: python3 -I scripts/planilha-perfeita-visao-para-csv.py <planilha.xlsx> <saida.csv>
Só usa a biblioteca padrão (lê o XML de dentro do .xlsx).
"""
import csv, datetime, re, sys, zipfile
import xml.etree.ElementTree as ET

M = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'
R = '{http://schemas.openxmlformats.org/officeDocument/2006/relationships}'


def load(path):
    z = zipfile.ZipFile(path)
    ss = [''.join(t.text or '' for t in si.iter(M + 't')) for si in ET.fromstring(z.read('xl/sharedStrings.xml')).findall(M + 'si')]
    rels = {r.get('Id'): r.get('Target') for r in ET.fromstring(z.read('xl/_rels/workbook.xml.rels'))}
    sheets = {}
    for s in ET.fromstring(z.read('xl/workbook.xml')).find(M + 'sheets'):
        p = 'xl/' + rels[s.get(R + 'id')].replace('/xl/', '').lstrip('/')
        data = {}
        for row in ET.fromstring(z.read(p)).iter(M + 'row'):
            for c in row.findall(M + 'c'):
                v = c.find(M + 'v')
                val = v.text if v is not None else None
                if c.get('t') == 's' and val is not None:
                    val = ss[int(val)]
                elif c.get('t') == 'inlineStr':
                    val = ''.join(x.text or '' for x in c.iter(M + 't'))
                ref = c.get('r')
                col = re.match(r'[A-Z]+', ref).group()
                if val not in (None, ''):
                    data.setdefault(int(ref[len(col):]), {})[col] = val.strip() if isinstance(val, str) else val
        sheets[s.get('name')] = data
    return sheets


def iso(serial):
    try:
        return (datetime.date(1899, 12, 30) + datetime.timedelta(days=int(float(serial)))).isoformat()
    except (TypeError, ValueError):
        return ''


def num(v):
    try:
        return f'{float(v):.2f}'
    except (TypeError, ValueError):
        return ''


def main(src, dst):
    sheets = load(src)
    out = []
    for n, r in sorted(sheets['Ligações'].items()):
        if n == 1 or 'C' not in r:
            continue
        out.append({
            'origem': f'Ligações!{n}', 'cidade': r.get('A', ''), 'nome': r['C'],
            'telefone': r.get('E') or r.get('D', ''), 'endereco': r.get('G', ''),
            'data_compra': iso(r.get('H')), 'valor': num(r.get('I')), 'indicacao': r.get('J', ''),
            'os': r.get('B', ''), 'observacoes': r.get('P', ''), 'cpf': '', 'nascimento': '',
        })
    for n, r in sorted(sheets['Clientes'].items()):
        if n == 1 or 'B' not in r:
            continue
        obs = [x for x in [r.get('U', ''), f"Local de atendimento: {r['K']}" if r.get('K') else '', f"Vendedor(a): {r['M']}" if r.get('M') else ''] if x]
        out.append({
            'origem': f'Clientes!{n}', 'cidade': r.get('I', ''), 'nome': r['B'], 'telefone': r.get('C', ''),
            'endereco': ' - '.join(x for x in [r.get('G', ''), r.get('H', '')] if x),
            'data_compra': iso(r.get('L')), 'valor': num(r.get('N')), 'indicacao': '',
            'os': r.get('A', ''), 'observacoes': '. '.join(obs), 'cpf': r.get('D', ''), 'nascimento': iso(r.get('F')),
        })
    with open(dst, 'w', newline='', encoding='utf-8') as f:
        w = csv.DictWriter(f, fieldnames=list(out[0]))
        w.writeheader()
        w.writerows(out)
    print(f'{len(out)} linhas gravadas em {dst}')


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
