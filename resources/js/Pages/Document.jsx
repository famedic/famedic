import Card from "@/Components/Card";
import { Subheading, GradientHeading } from "@/Components/Catalyst/heading";
import VerticalNavbar from "@/Components/VerticalNavbar";
import FocusedLayout from "@/Layouts/FocusedLayout";
import Markdown from "react-markdown";
import remarkGfm from "remark-gfm";

export default function Document({ name, markdown }) {
	return (
		<FocusedLayout title={"Documentación legal - " + name}>
			<div className="grid gap-x-8 gap-y-6 lg:grid-cols-[auto,1fr]">
				<div className="lg:relative">
					<div className="lg:sticky lg:top-4">
						<Subheading>Documentación legal</Subheading>
						<VerticalNavbar
							links={[
								{
									label: "Política de privacidad",
									url: route("privacy-policy"),
									current: route().current("privacy-policy"),
								},
								{
									label: "Términos y condiciones de servicio",
									url: route("terms-of-service"),
									current:
										route().current("terms-of-service"),
								},
							]}
							className="mt-4 max-w-sm"
						/>
					</div>
				</div>
				<Card className="prose mb-12 w-full max-w-5xl p-4 dark:prose-invert marker:text-famedic-light prose-a:text-famedic-light lg:mt-10 lg:p-12">
					<GradientHeading noDivider>{name}</GradientHeading>
					<Markdown remarkPlugins={[remarkGfm]}>{markdown}</Markdown>
				</Card>
			</div>
		</FocusedLayout>
	);
}
