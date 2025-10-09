import BasicInfoForm from "@/Pages/Account/BasicInfoForm";
import ContactInfoForm from "@/Pages/Account/ContactInfoForm";
import UpdatePasswordForm from "@/Pages/Account/UpdatePasswordForm";
import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Divider } from "@/Components/Catalyst/divider";

export default function Account() {
	return (
		<SettingsLayout title="Mi cuenta">
			<GradientHeading noDivider>Mi cuenta</GradientHeading>

			<Divider className="my-10 mt-6" />

			<BasicInfoForm />

			<Divider className="my-10" soft />

			<ContactInfoForm />

			<Divider className="my-10" soft />

			<UpdatePasswordForm />
		</SettingsLayout>
	);
}
