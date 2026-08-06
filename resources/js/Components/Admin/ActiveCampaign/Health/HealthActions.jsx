import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";

export default function HealthActions({ actions = [] }) {
	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Acciones rápidas
				</h2>
				<p className="text-xs text-zinc-500">
					Atajos a pantallas existentes. Sin inventar operaciones.
				</p>
			</div>

			<div className="flex flex-wrap gap-2">
				{actions.map((action) =>
					action.enabled && action.href ? (
						<Button key={action.id} href={action.href} outline>
							{action.label}
						</Button>
					) : (
						<div key={action.id} className="space-y-1">
							<Button outline disabled title={action.hint || "No disponible"}>
								{action.label}
							</Button>
							{action.hint ? (
								<Text className="max-w-xs text-[11px] text-zinc-400">
									{action.hint}
								</Text>
							) : null}
						</div>
					),
				)}
			</div>
		</section>
	);
}
