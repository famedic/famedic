import AccountTabs from "@/Pages/Account/AccountTabs";
import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";

export default function Account() {
	return (
		<SettingsLayout title="Mi cuenta">
			<div className="space-y-2">
				<GradientHeading noDivider>Mi cuenta</GradientHeading>
				<Text className="max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
					Administra tu información personal, datos de contacto y contraseña desde las
					secciones siguientes.
				</Text>
			</div>

			<AccountTabs />
		</SettingsLayout>
	);
}
