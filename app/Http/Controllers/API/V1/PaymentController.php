<?php

namespace App\Http\Controllers\API\V1;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\RazorpayService;
use App\Models\Payment;
use App\Models\MrMaster;
use App\Models\OpdOnlineAppointment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class PaymentController extends Controller
{
    protected $razorpayService;

    public function __construct(RazorpayService $razorpayService)
    {
        $this->razorpayService = $razorpayService;
    }

    // Method to initiate payment
    public function createPayment(Request $request)
    {
        // Validate incoming request data
        $validated = $request->validate([
            'OPDOnlineAppointmentID' => 'required|integer|exists:opd_onlineappointments,OPDOnlineAppointmentID',
            'AmountPaid' => 'required|numeric', // Ensure this is a valid number
            'PaymentMode' => 'required|string|max:50', // Validate payment mode
            'CreatedBy' => 'nullable|integer', // Optional creator ID
        ]);

        DB::beginTransaction(); // Start the transaction

        try {
            // Set CreatedBy to null if not provided in the request
            $createdBy = isset($validated['CreatedBy']) ? $validated['CreatedBy'] : null;

            // Create a new payment record
            $payment = Payment::create([
                'OPDOnlineAppointmentID' => $validated['OPDOnlineAppointmentID'],
                'PaymentDate' => now(), // Set current date/time
                'PaymentMode' => $validated['PaymentMode'],
                'PaymentStatus' => 'Pending', // Initial status
                'AmountPaid' => $validated['AmountPaid'],
                'TransactionID' => null, // Will be updated after Razorpay order creation
                'CreatedBy' => $createdBy, // Use the extracted or default value
            ]);

            // Prepare order data for Razorpay
            $orderData = [
                'amount' => $validated['AmountPaid'], // Convert to paise
                'currency' => 'INR',
                'receipt' => $payment->PaymentID, // Associate the receipt with payment ID
            ];

            // Create order with Razorpay service
            $razorpayOrder = $this->razorpayService->createOrder($orderData);

            // Update payment with Razorpay transaction ID
            $payment->TransactionID = $razorpayOrder->id;
            $payment->save(); // Save the payment record

            DB::commit(); // Commit the transaction

            // Return successful response
            return response()->json([
                'message' => 'Payment initiated successfully.',
                'payment_id' => $payment->PaymentID,
                'order_id' => $razorpayOrder->id,
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Payment initiation failed: ' . $e->getMessage(), [
                'request' => $request->all(), // Log the request data
                'stack_trace' => $e->getTraceAsString() // Log the stack trace
            ]);
            DB::rollBack(); // Rollback the transaction on error
            return response()->json([
                'message' => 'Payment initiation failed: ' . $e->getMessage(),
            ], 500);
        }
    }



    // Handle Razorpay payment callback
    // public function handlePaymentCallback(Request $request)
    // {
    //     $webhookBody = $request->getContent(); // Raw request body
    //     $webhookSignature = $request->header('X-Razorpay-Signature'); // Razorpay signature header

    //     // Check if the Razorpay signature is missing
    //     if (is_null($webhookSignature)) {
    //         \Log::error('Missing Razorpay signature.', [
    //             'headers' => $request->headers->all(), // Log all headers for debugging
    //             'webhookBody' => $webhookBody, // Log the raw webhook payload for analysis
    //         ]);

    //         return response()->json(['message' => 'Missing Razorpay signature'], 400);
    //     }

    //     $webhookSecret = config('razorpay.webhook_secret'); // Fetch webhook secret from config

    //     // Verify the webhook signature
    //     $isValidSignature = $this->razorpayService->verifyWebhookSignature($webhookBody, $webhookSignature, $webhookSecret);

    //     if ($isValidSignature) {
    //         $payload = json_decode($webhookBody, true);
    //         $paymentId = $payload['payload']['payment']['entity']['id'];

    //         // Find the payment record associated with this transaction
    //         $payment = Payment::where('TransactionID', $paymentId)->first();
    //         if ($payment && $payment->PaymentStatus !== 'Completed') {
    //             $payment->PaymentStatus = 'Completed'; // Mark payment as completed
    //             $payment->PaymentDate = now(); // Update payment date
    //             $payment->save();
    //         }

    //         return response()->json(['message' => 'Webhook processed successfully'], 200);
    //     }

    //     // Return invalid signature response
    //     return response()->json(['message' => 'Invalid signature'], 400);
    // }



    // Method to reconcile payment (for internal use, if needed)
    public function reconcilePayment($paymentId)
    {
        // Example method; implement reconciliation logic as needed
        $result = $this->razorpayService->fetchPaymentDetails($paymentId);
        return response()->json($result);
    }

    // Method to get payment history
    public function getPaymentHistory(Request $request)
    {
        // Example method; implement payment history retrieval logic as needed
        $payments = Payment::all(); // Replace with appropriate logic if needed
        return response()->json($payments);
    }

    // Method to get payment details by ID
    public function getPaymentById(Request $request)
    {
        $paymentId = $request->input('payment_id');

        if (!$paymentId || !is_numeric($paymentId)) {
            return response()->json(['message' => 'Invalid payment ID'], 400);
        }

        $payment = Payment::find($paymentId);

        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        return response()->json($payment);
    }



    // public function handlePaymentCallback(Request $request)
    // {
    //     // Simulate a webhook payload for testing purposes
    //     $webhookBody = $request->getContent(); // Replace with your test payload
    //     $webhookSignature = $request->header('X-Razorpay-Signature'); // Dummy signature for testing

    //     // Simulate Razorpay webhook verification (skip actual signature verification for local testing)
    //     $isValidSignature = true;

    //     if (!$isValidSignature) {
    //         return response()->json(['message' => 'Invalid signature'], 400);
    //     }

    //     $payload = json_decode($webhookBody, true);

    //     $paymentId = $payload['payment_id'] ?? 'DUMMY_PAYMENT_ID';
    //     $paymode = $payload['paymentmode'] ?? 'Online';
    //     $amount = $payload['amount'] ?? 100.00;
    //     $mrNo = $payload['MRNo'] ?? null;
    //     $registrationId = $payload['RegistrationId'] ?? null;
    //     $patientName = $payload['patientname'] ?? 'Unknown'; // Default patient name if not provided

    //     // Checking if MRNo exists or we need to create(mr_master)
    //     if (!$mrNo) {
    //         try {
    //             $mrNo = $this->generateMRNo(); // Generate new MRNo(calling function)
    //         } catch (\Exception $e) {
    //             return response()->json(['message' => 'Failed to generate MRNo: ' . $e->getMessage()], 500);
    //         }
    //     }

    //     $mrRecord = DB::table('mr_master')->where('MRNo', $mrNo)->first();

    //     if (!$mrRecord) {
    //         // Creataing a new MRNo record if it does not exist
    //         $newMrNo = DB::table('mr_master')->insertGetId([
    //             'MRNo' => $mrNo,
    //             'MRDate' => now(),
    //             'PatientName' => $patientName, // Use provided patient name(next (another field to be added))
    //         ]);

    //         if (!$newMrNo) {
    //             return response()->json(['message' => 'Failed to create new MRNo'], 500);
    //         }
    //     }

    //     if (!$registrationId) {
    //         // Adding a new registration record if RegistrationID is not provided
    //         $registrationId = DB::table('opd_registrations')->insertGetId([
    //             'MRNo' => $mrNo,
    //             'RegistrationDate' => now(),
    //             'RegistrationFee' => $amount, // Assign the amount for RF
    //             'CreatedOn' => now(),
    //         ]);

    //         if (!$registrationId) {
    //             return response()->json(['message' => 'Failed to create new registration'], 500);
    //         }
    //     }

    //     // Update the opd_registrations table
    //     $updatedRows = DB::table('opd_registrations')
    //         ->where('RegistrationID', $registrationId)
    //         ->update([
    //             'PaymentMode' => $paymode,
    //             'CashAmount' => $amount,
    //             'Amount' => $amount,
    //             'AmountReceived' => $amount,
    //             'ModifiedOn' => now(),
    //         ]);

    //     if ($updatedRows > 0) {
    //         // Update the opd_consultation table to reflect payment status
    //         $consultationUpdatedRows = DB::table('opd_consultations')
    //             ->where('RegistrationID', $registrationId)
    //             ->update([
    //                 'Pending' => 0, // Set Pending to false (0) to indicate payment is complete
    //                 'ModifiedOn' => now(),
    //                 'Remarks' => 'Payment completed', // Add a remark for the payment
    //             ]);

    //         return response()->json([
    //             'message' => 'Payment processed successfully',
    //             'consultationUpdated' => $consultationUpdatedRows > 0 ? 'Updated' : 'Not Found',
    //         ], 200);
    //     }

    //     return response()->json(['message' => 'No registration found to update'], 404);
    // }

    // private function generateMRNo()
    // {
    //     try {
    //         // Retrieve the MR counter from the database
    //         $mrParameter = DB::table('mr_parameter')->where('ID', 1)->first();
    //         $mrCounter = $mrParameter->MRCounter ?? 0;

    //         // Increment the counter
    //         DB::table('mr_parameter')->where('ID', 1)->update(['MRCounter' => $mrCounter + 1]);

    //         // Generate MRNo with year, month, and counter
    //         $currentYear = now()->year % 100; // Last two digits of the year
    //         $currentMonth = str_pad(now()->month, 2, '0', STR_PAD_LEFT); // Two-digit month
    //         $mrNo = $currentYear . $currentMonth . str_pad($mrCounter, 5, '0', STR_PAD_LEFT);

    //         // Append checksum
    //         $checksum = array_sum(str_split($mrNo)) % 9;
    //         $mrNo .= $checksum;

    //         return $mrNo;
    //     } catch (\Exception $e) {
    //         throw new \Exception('Failed to generate MRNo: ' . $e->getMessage());
    //     }
    // }
    public function handlePaymentCallback(Request $request)
    {
        // Retrieve webhook payload and signature
        $webhookBody = $request->getContent();
        $webhookSignature = $request->header('X-Razorpay-Signature');

        // Simulate Razorpay webhook signature verification (skip actual verification for local testing)
        $isValidSignature = true;

        if (!$isValidSignature) {
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        // Decode the webhook payload
        $payload = json_decode($webhookBody, true);

        // Log the payload for debugging purposes
        Log::info('Received Webhook Payload: ', $payload);

        // Extract payment details from the payload
        $paymentId = $payload['payment_id'] ?? 'DUMMY_PAYMENT_ID';
        $paymode = $payload['paymentmode'] ?? 'Online';
        $amount = $payload['amount'] ?? 1000.00;
        $mrNo = $payload['MRNo'] ?? null;
        $patientName = $payload['patientname'] ?? 'Unknown';
        $appointmentId = $payload['appointment_id'];

        // If MRNo is not provided in the payload, generate one
        if (!$mrNo) {
            try {
                $mrNo = $this->generateMRNo();
                Log::info('Generated MRNo: ' . $mrNo);
            } catch (\Exception $e) {
                return response()->json(['message' => 'Failed to generate MRNo: ' . $e->getMessage()], 500);
            }
        }

        // Check if the MRNo exists in the database
        $mrRecord = DB::table('mr_master')->where('MRNo', $mrNo)->first();

        if (!$mrRecord) {
            // Create new MRNo and associated records
            try {
                Log::info('Attempting to create MRNo: ' . $mrNo);
                $newMrNoRecord = MrMaster::create([
                    'MRNo' => $mrNo,
                    'MRDate' => now(),
                    'PatientName' => $patientName,
                ]);
                Log::info('New MRNo record created successfully: ' . $mrNo);

                // Insert new record into opd_registrations table
                $registrationId = DB::table('opd_registrations')->insertGetId([
                    'MRNo' => $mrNo,
                    'RegistrationDate' => now(),
                    'ConsultationDate' => now(),
                    'RegistrationFee' => 100,
                    'Amount' => $amount,
                    'PaymentMode' => $paymode,
                    'CreatedBy' => auth()->id() ?? 1,
                    'CreatedOn' => now(),
                ]);
                Log::info('New record created in opd_registrations for MRNo: ' . $mrNo);

                // Insert new record into opd_consultations table
                DB::table('opd_consultations')->insert([
                    'RegistrationID' => $registrationId,
                    'ConsultationDate' => now(),
                    'ConsultedAt' => now(),
                    'PatientName' => $patientName,
                    'CreatedBy' => auth()->id() ?? 1,
                    'CreatedOn' => now(),
                ]);
                Log::info('New record created in opd_consultations for RegistrationID: ' . $registrationId);
            } catch (\Exception $e) {
                Log::error('Error during MRNo creation: ' . $e->getMessage());
                return response()->json(['message' => 'Error during MRNo creation: ' . $e->getMessage()], 500);
            }
        } else {
            Log::info('MRNo already exists: ' . $mrNo);

            // Update existing record in opd_registrations table
            DB::table('opd_registrations')
                ->where('MRNo', $mrNo)
                ->update([
                    'ConsultationDate' => now(),
                    'Amount' => $amount,
                    'PaymentMode' => $paymode,
                    'ModifiedBy' => auth()->id() ?? 1,
                    'ModifiedOn' => now(),
                ]);
            Log::info('opd_registrations updated for existing MRNo: ' . $mrNo);
        }

        // Strictly use update for payment records
        try {
            // Check if the payment record exists
            $paymentExists = DB::table('payments')
                ->where('payment_id', $paymentId)
                ->where('appointment_id', $appointmentId)
                ->exists();

            if ($paymentExists) {
                // Update the existing payment record
                DB::table('payments')
                    ->where('payment_id', $paymentId)
                    ->where('appointment_id', $appointmentId)
                    ->update([
                        'amount' => $amount,
                        'status' => 'captured',
                        'TransactionID' => $payload['transaction_id'] ?? null,
                        'updated_at' => now(),
                    ]);
                Log::info('Payment record updated successfully for PaymentID: ' . $paymentId);
            } else {
                Log::error('Payment record not found for PaymentID: ' . $paymentId);
                return response()->json(['message' => 'Payment record not found. Update failed.'], 404);
            }
        } catch (\Exception $e) {
            Log::error('Error processing payment: ' . $e->getMessage());
            return response()->json(['message' => 'Error processing payment: ' . $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Payment callback processed successfully']);
    }


    private function generateMRNo()
    {
        try {
            // Retrieve the MR counter from the database
            $mrParameter = DB::table('mr_parameter')->where('ID', 1)->first();

            if (!$mrParameter) {
                Log::error("MR Parameter not found. ID: 1");
                throw new \Exception('MR Parameter not found.');
            }

            $mrCounter = $mrParameter->MRCounter ?? 0;

            // Increment the counter
            DB::table('mr_parameter')->where('ID', 1)->update(['MRCounter' => $mrCounter + 1]);

            // Generate MRNo with year, month, and counter
            $currentYear = now()->year % 100; // Last two digits of the year
            $currentMonth = str_pad(now()->month, 2, '0', STR_PAD_LEFT); // Two-digit month
            $mrNo = $currentYear . $currentMonth . str_pad($mrCounter, 5, '0', STR_PAD_LEFT);

            // Append checksum
            $checksum = array_sum(str_split($mrNo)) % 9;
            $mrNo .= $checksum;

            Log::info("Generated MRNo: " . $mrNo);
            return $mrNo;
        } catch (\Exception $e) {
            Log::error("Failed to generate MRNo: " . $e->getMessage());
            throw new \Exception('Failed to generate MRNo: ' . $e->getMessage());
        }
    }
}
