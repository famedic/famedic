import { Button } from "@/Components/Catalyst/button";

export default function ForgotPasswordLink({ className }) {
	return (
		<div className="flex justify-end">
			<Button
				plain
				href={route("password.request")}
				className={className}
			>
				¿Olvidaste tu contraseña?
			</Button>
		</div>
	);
}
