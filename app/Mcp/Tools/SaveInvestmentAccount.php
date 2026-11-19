<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\InvestmentAccount;
use App\Support\InvestmentAccountData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Crea una cuenta de inversión (CETES, fondo, acciones, Afore…) o, con `id`, cambia una existente (solo lo que se manda; `closed_on` la cierra). `currency` de 3 letras (MXN por defecto; no se puede cambiar si ya tiene registros). Luego registra aportaciones y su valor con record-investment.')]
class SaveInvestmentAccount extends Tool
{
    public function handle(Request $request): Response
    {
        $request->validate(['id' => ['nullable', 'integer']]);
        $account = $request->get('id') ? $this->find(InvestmentAccount::class, $request->get('id'), 'una cuenta de inversión') : new InvestmentAccount;
        $given = collect($request->all())->except('id')->all();
        $data = InvestmentAccountData::validate($account->exists ? array_merge(InvestmentAccountData::toInput($account), $given) : $given + ['type' => 'other'], $account->exists ? $account : null);

        $creating = ! $account->exists;
        $account->fill($data)->save();

        return $this->json(['saved' => $creating ? 'created' : 'updated', 'account' => $account->only(['id', 'name', 'institution', 'type', 'currency']) + ['closed_on' => $account->closed_on?->toDateString()]]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Para cambiar una existente (de get-finances). Sin él se crea.'),
            'name' => $schema->string()->description('Nombre: "CETES directo", "Afore".'),
            'institution' => $schema->string()->description('Banco o casa de bolsa.'),
            'type' => $schema->string()->enum(array_keys(InvestmentAccount::TYPES))->description('cetes, fund, stocks, crypto, savings, retirement u other (por defecto).'),
            'currency' => $schema->string()->description('MXN (por defecto), USD…'),
            'notes' => $schema->string(),
            'closed_on' => $schema->string()->description('Fecha en que se cerró, AAAA-MM-DD.'),
        ];
    }
}
